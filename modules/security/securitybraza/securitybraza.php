<?php
/**
 * SecurityBraza - Módulo de Autenticação de 2 Fatores via Email para WHMCS
 * 
 * @package    WHMCS
 * @author     SecurityBraza
 * @version    1.1.1 - Correções de Bugs
 */

if (!defined("WHMCS")) {
    die("Acesso negado");
}

use WHMCS\Database\Capsule;
use WHMCS\User\Client;
use WHMCS\Authentication\TwoFactor\Module;

/**
 * Configuração do módulo
 */
function securitybraza_config()
{
    return [
        "FriendlyName" => [
            "Type" => "System",
            "Value" => "SecurityBraza 2FA Email"
        ],
        "ShortDescription" => [
            "Type" => "System",
            "Value" => "Autenticação via código enviado por email e WhatsApp."
        ],
        "Description" => [
            "Type" => "System", 
            "Value" => "SecurityBraza envia um código de verificação por email que expira após alguns minutos. O usuário deve inserir o código recebido para completar o login."
        ],
        "CodeExpiry" => [
            "FriendlyName" => "Tempo de expiração do código (min)",
            "Type" => "text",
            "Size" => "5",
            "Default" => "5",
            "Description" => "Defina o tempo de expiração do código de verificação em minutos."
        ],
        "CodeLength" => [
            "FriendlyName" => "Tamanho do Código",
            "Type" => "text",
            "Size" => "5",
            "Default" => "6",
            "Description" => "Defina o número de dígitos do código de verificação"
        ]
    ];
}

/**
 * Função auxiliar para obter cliente ID do usuário - SIMPLIFICADO
 */
function getClientIdFromUser($userid, $email = null)
{
    // Busca o cliente principal do usuário
    $userClient = Capsule::table('tblusers_clients')
        ->where('auth_user_id', $userid)
        ->where('owner', 1)
        ->first();

    if ($userClient) {
        return $userClient->client_id;
    }

    // Fallback: se não encontrou relação principal, tenta buscar por email
    if ($email) {
        $client = Capsule::table('tblclients')->where('email', $email)->first();
        if ($client) {
            // Verifica se o usuário tem alguma permissão para este cliente
            $userClientRelation = Capsule::table('tblusers_clients')
                ->where('auth_user_id', $userid)
                ->where('client_id', $client->id)
                ->first();
                
            if ($userClientRelation) {
                return $client->id;
            }
        }
    }

    // Se não encontrou cliente, usa o userid (pode ser admin)
    return $userid;
}

/**
 * Função auxiliar para verificar/criar template de email - CACHE SIMPLES
 */
function ensureEmailTemplate()
{
    static $templateChecked = false;
    
    if (!$templateChecked) {
        $template = Capsule::table('tblemailtemplates')->where('name', 'SecurityBraza: Email 2FA')->first();
        if (!$template) {
            Capsule::table('tblemailtemplates')->insert([
                'type' => 'general',
                'name' => 'SecurityBraza: Email 2FA',
                'subject' => 'Seu código de verificação da Hostbraza',
                'message' => '<p>Olá {$client_first_name},</p><p>Seu código de verificação é: <strong style="font-size: 24px;">{$verification_code}</strong></p><p>Este código expira em {$expiry_minutes} minutos.</p><p>IP: {$ip_address}<br />Data/Hora: {$timestamp}</p><p>Se você não solicitou este código, ignore este email.</p>',
                'custom' => 1,
                'disabled' => 0,
                'language' => '',
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }
        $templateChecked = true;
    }
}

/**
 * Função auxiliar para limpar códigos expirados - CLEANUP AUTOMÁTICO
 */
function cleanupExpiredCodes($userid = null)
{
    $query = Capsule::table('mod_securitybraza_codes')
        ->where('expires_at', '<', date('Y-m-d H:i:s'));
    
    if ($userid) {
        $query->where('userid', $userid);
    }
    
    $query->delete();
}

/**
 * Função para enviar novo código - OTIMIZADA
 */
function sendNewVerificationCode($userid, $email, $firstname, $code_length, $code_expiry)
{
    // Gera novo código
    $code = generateSecurityBrazaCode($code_length);
    
    // Remove códigos anteriores
    Capsule::table('mod_securitybraza_codes')->where('userid', $userid)->delete();
    
    // Armazena novo código
    Capsule::table('mod_securitybraza_codes')->insert([
        'userid' => $userid,
        'code' => hash('sha256', 'rice' . $code),
        'attempts' => 0,
        'created_at' => date('Y-m-d H:i:s'),
        'expires_at' => date('Y-m-d H:i:s', strtotime("+{$code_expiry} minutes"))
    ]);
    
    // Template com cache
    ensureEmailTemplate();
    
    // Obtém cliente ID
    $clientid = getClientIdFromUser($userid, $email);
    
    // Prepara variáveis do email
    $emailVars = [
        'verification_code' => $code,
        'expiry_minutes' => $code_expiry,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'N/A',
        'timestamp' => date('d/m/Y H:i:s')
    ];
    
    // Envia email de forma assíncrona para melhorar performance
    try {
        $result = localAPI('SendEmail', [
            'messagename' => 'SecurityBraza: Email 2FA',
            'id' => $clientid,
            'customvars' => base64_encode(serialize($emailVars))
        ]);
        
        // Log para debug da demora no envio
        logActivity("SecurityBraza: Email enviado para userid {$userid} - Resultado: " . json_encode($result));
        
        return true;
    } catch (Exception $e) {
        logActivity("SecurityBraza: Erro ao enviar email - " . $e->getMessage());
        return false;
    }
}

/**
 * Ativa o 2FA para o cliente - CORRIGIDO
 */
function securitybraza_activate($params)
{
    $userinfo = $params['user_info'] ?? [];
    $userid = $userinfo['id'] ?? null;
    $email = $userinfo['email'] ?? null;
    $firstname = $userinfo['firstname'] ?? '';
    $code_length = isset($params['settings']['CodeLength']) && is_numeric($params['settings']['CodeLength']) 
        ? (int)$params['settings']['CodeLength'] 
        : 6;
    $code_expiry = isset($params['settings']['CodeExpiry']) && is_numeric($params['settings']['CodeExpiry']) 
        ? (int)$params['settings']['CodeExpiry'] 
        : 5;

    if (!$userid || !$email) {
        return '<div class="alert alert-danger">Não foi possível identificar o usuário.</div>';
    }

    // Cria tabelas, se necessário
    createSecurityBrazaTables();



    // Cleanup automático
    cleanupExpiredCodes($userid);

    // Verifica se já existe um código válido
    $existingCode = Capsule::table('mod_securitybraza_codes')
        ->where('userid', $userid)
        ->where('expires_at', '>', date('Y-m-d H:i:s'))
        ->first();

    if (!$existingCode) {
        // Envia novo código
        sendNewVerificationCode($userid, $email, $firstname, $code_length, $code_expiry);
    }

    // Mostra mensagem de erro se houver
    $errorMessage = '';
    if (isset($params['verifyError']) && $params['verifyError']) {
        $errorMessage = '<div class="alert alert-danger">' . $params['verifyError'] . '</div>';
    }

    // Mostra mensagem informativa se código já existe
    $infoMessage = '';
    if ($existingCode) {
        $infoMessage = '<div class="alert alert-info">Um código de verificação já foi enviado e ainda está válido. Verifique seu email.</div>';
    }

    return '
        <p>Um código foi enviado para seu email. Digite o código abaixo para ativar a autenticação em duas etapas:</p>
        ' . $infoMessage . $errorMessage . '
        <form method="post">
            <div class="form-group">
                <input type="text" name="verifykey" class="form-control" 
                    placeholder="Digite o código de ' . $code_length . ' dígitos" 
                    maxlength="' . $code_length . '" 
                    autocomplete="off" required autofocus>
            </div>
            <div class="form-group">
                <button type="submit" class="btn btn-primary">Verificar e Ativar</button>
            </div>
        </form>
    ';
}

/**
 * Verifica ativação - CORRIGIDO
 */
function securitybraza_activateverify($params)
{
    $userinfo = $params['user_info'] ?? [];
    $userid = $userinfo['id'] ?? null;
    $email = $userinfo['email'] ?? null;
    $code_length = isset($params['settings']['CodeLength']) && is_numeric($params['settings']['CodeLength']) 
        ? (int)$params['settings']['CodeLength'] 
        : 6;

    if (!$userid && $email) {
        $user = Capsule::table('tblusers')->where('email', $email)->first();
        if ($user) {
            $userid = $user->id;
        }
    }

    if (!$userid) {
        throw new WHMCS\Exception('Código inválido ou sessão expirada.');
    }

    // CORREÇÃO: Verifica se é solicitação de reenvio e não processa como código

    // Obtém o código digitado
    $inputCode = preg_replace('/[^0-9]/', '', $params['post_vars']['verifykey'] ?? '');

    // Validação melhorada
    if (!$inputCode) {
        throw new WHMCS\Exception('Você deve informar o código enviado por email.');
    }

    if (strlen($inputCode) !== $code_length) {
        throw new WHMCS\Exception('O código deve ter exatamente ' . $code_length . ' dígitos.');
    }

    // Cleanup automático
    cleanupExpiredCodes($userid);

    $storedCode = Capsule::table('mod_securitybraza_codes')
        ->where('userid', $userid)
        ->where('expires_at', '>', date('Y-m-d H:i:s'))
        ->where('attempts', '<', 3)
        ->orderBy('created_at', 'desc')
        ->first();

    if (!$storedCode) {
        throw new WHMCS\Exception('Código expirado ou não encontrado. Solicite um novo código.');
    }

    // Incrementa tentativas
    Capsule::table('mod_securitybraza_codes')
        ->where('id', $storedCode->id)
        ->increment('attempts');

    // Verifica se o código está correto
    if (hash('sha256', 'rice' . $inputCode) !== $storedCode->code) {
        $remainingAttempts = 3 - ($storedCode->attempts + 1);
        
        // Excluir código após 3 tentativas
        if ($storedCode->attempts >= 2) {
            Capsule::table('mod_securitybraza_codes')->where('id', $storedCode->id)->delete();
            throw new WHMCS\Exception('Código inválido. Limite de tentativas excedido. Solicite um novo código.');
        }
        
        throw new WHMCS\Exception('Código incorreto. Você tem ' . $remainingAttempts . ' tentativas restantes.');
    }

    // Código válido, remove para não reutilizar
    Capsule::table('mod_securitybraza_codes')->where('id', $storedCode->id)->delete();

    return array('settings' => array());
}

/**
 * Challenge durante login - MELHORADO
 */
function securitybraza_challenge($params)
{
    $userinfo = $params['user_info'] ?? $params['admin_info'] ?? [];
    $userid = $userinfo['id'] ?? null;
    $email = $userinfo['email'] ?? null;
    $firstname = $userinfo['firstname'] ?? $userinfo['first_name'] ?? '';
    $lastname = $userinfo['lastname'] ?? $userinfo['last_name'] ?? '';

    if (!$userid && $email) {
        $user = Capsule::table('tblusers')->where('email', $email)->first();
        if ($user) {
            $userid = $user->id;
        }
    }

    if (!$userid) {
        return '<div class="alert alert-danger">Erro: Não foi possível identificar o usuário. Por favor, tente fazer login novamente.</div>';
    }

    // Template com cache
    ensureEmailTemplate();

    try {
        createSecurityBrazaTables();

        $code_length = isset($params['settings']['CodeLength']) && is_numeric($params['settings']['CodeLength']) 
            ? (int)$params['settings']['CodeLength'] 
            : 6;
        $code_expiry = isset($params['settings']['CodeExpiry']) && is_numeric($params['settings']['CodeExpiry']) 
            ? (int)$params['settings']['CodeExpiry'] 
            : 5;

        // Cleanup automático
        cleanupExpiredCodes($userid);

        // Verifica se já existe um código válido e não expirado
        $existingCode = Capsule::table('mod_securitybraza_codes')
            ->where('userid', $userid)
            ->where('expires_at', '>', date('Y-m-d H:i:s'))
            ->first();

        if ($existingCode) {
            return '
                <div class="alert alert-info">
                    Um código de verificação já foi enviado e ainda está válido. Verifique seu email.
                </div>
                <form method="post" action="dologin.php">
                    <div class="text-center">
                        <div class="form-group">
                            <input type="text" name="key" id="key" class="form-control input-lg text-center" 
                                placeholder="Digite o código de ' . $code_length . ' dígitos" 
                                maxlength="' . $code_length . '" 
                                autocomplete="off" 
                                autofocus
                                required>
                        </div>
                    </div>
                </form>
                <script>document.getElementById("key").focus();</script>
            ';
        }

        // Envia novo código
        sendNewVerificationCode($userid, $email, $firstname, $code_length, $code_expiry);

        return '
            <form method="post" action="dologin.php">
                <div class="text-center">
                    <div class="form-group">
                        <input type="text" name="key" id="key" class="form-control input-lg text-center" 
                            placeholder="Digite o código de ' . $code_length . ' dígitos" 
                            maxlength="' . $code_length . '" 
                            autocomplete="off" 
                            autofocus
                            required>
                    </div>
                </div>
            </form>
            <script>document.getElementById("key").focus();</script>
        ';
    } catch (Exception $e) {
        logActivity("SecurityBraza 2FA Error: " . $e->getMessage());

        return '
            <div class="alert alert-danger">
                <strong>Erro!</strong> Não foi possível enviar o código de verificação. Por favor, tente novamente.
            </div>
            <div class="text-center">
                <a href="login.php" class="btn btn-default">Voltar ao Login</a>
            </div>
        ';
    }
}

/**
 * Verifica o código inserido - MELHORADO
 */
function securitybraza_verify($params)
{
    // Obtém informações do usuário
    $userinfo = $params['user_info'] ?? $params['admin_info'] ?? [];
    $userid = $userinfo['id'] ?? null;
    $email = $userinfo['email'] ?? null;
    
    // Se não temos userid, tenta buscar pelo email
    if (!$userid && $email) {
        $user = Capsule::table('tblusers')->where('email', $email)->first();
        if ($user) {
            $userid = $user->id;
        }
    }
    
    if (!$userid) {
        return false;
    }
    
    // Obtém o código digitado
    $code = preg_replace('/[^0-9]/', '', $params['post_vars']['key'] ?? '');
    
    if (empty($code)) {
        return false;
    }
    
    try {
        // Cleanup automático
        cleanupExpiredCodes($userid);
        
        // Busca código válido
        $storedCode = Capsule::table('mod_securitybraza_codes')
            ->where('userid', $userid)
            ->where('expires_at', '>', date('Y-m-d H:i:s'))
            ->where('attempts', '<', 3)
            ->orderBy('created_at', 'desc')
            ->first();
        
        if (!$storedCode) {
            return false;
        }
        
        // Incrementa tentativas
        Capsule::table('mod_securitybraza_codes')
            ->where('id', $storedCode->id)
            ->increment('attempts');
        
        // Verifica código
        if (hash('sha256', 'rice' . $code) === $storedCode->code) {
            // Remove código usado
            Capsule::table('mod_securitybraza_codes')
                ->where('id', $storedCode->id)
                ->delete();
            
            // Registra login bem-sucedido
            Capsule::table('mod_securitybraza_logs')->insert([
                'userid' => $userid,
                'action' => 'login_success',
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'N/A',
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
            return true;
        }
        
        // Se excedeu tentativas, remove código
        if ($storedCode->attempts >= 2) {
            Capsule::table('mod_securitybraza_codes')
                ->where('id', $storedCode->id)
                ->delete();
        }
        
        return false;
        
    } catch (Exception $e) {
        logActivity("SecurityBraza 2FA Verify Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Função auxiliar para gerar código
 */
function generateSecurityBrazaCode($length = 6)
{
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= mt_rand(0, 9);
    }
    return $code;
}

/**
 * Cria tabelas necessárias
 */
function createSecurityBrazaTables()
{
    // Tabela de códigos
    if (!Capsule::schema()->hasTable('mod_securitybraza_codes')) {
        Capsule::schema()->create('mod_securitybraza_codes', function ($table) {
            $table->increments('id');
            $table->integer('userid');
            $table->string('code', 64);
            $table->integer('attempts')->default(0);
            $table->timestamp('created_at');
            $table->timestamp('expires_at');
            $table->index(['userid', 'expires_at']);
        });
    }
    
    // Tabela de logs
    if (!Capsule::schema()->hasTable('mod_securitybraza_logs')) {
        Capsule::schema()->create('mod_securitybraza_logs', function ($table) {
            $table->increments('id');
            $table->integer('userid');
            $table->string('action', 50);
            $table->string('ip_address', 45);
            $table->timestamp('created_at');
            $table->index(['userid', 'created_at']);
        });
    }
}

/**
 * Hook para limpar códigos expirados periodicamente - MELHORADO
 */
add_hook('DailyCronJob', 1, function() {
    // Remove códigos expirados há mais de 24 horas
    Capsule::table('mod_securitybraza_codes')
        ->where('expires_at', '<', date('Y-m-d H:i:s', strtotime('-24 hours')))
        ->delete();
    
    // Remove logs antigos (mais de 30 dias)
    Capsule::table('mod_securitybraza_logs')
        ->where('created_at', '<', date('Y-m-d H:i:s', strtotime('-30 days')))
        ->delete();
        
    logActivity("SecurityBraza: Limpeza automática executada com sucesso.");
});