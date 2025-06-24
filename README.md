# SecurityBraza 2FA Email

Módulo de Autenticação de 2 Fatores via Email e WhatsApp para WHMCS

## 📋 Descrição

O SecurityBraza é um módulo de autenticação de dois fatores (2FA) para WHMCS que envia códigos de verificação por email. O módulo adiciona uma camada extra de segurança ao processo de login, exigindo que os usuários insiram um código recebido por email além de suas credenciais normais.

## ✨ Funcionalidades

- **Autenticação 2FA via Email**: Códigos de verificação enviados automaticamente por email
- **Códigos Personalizáveis**: Configure o tamanho do código (padrão: 6 dígitos)
- **Tempo de Expiração Configurável**: Defina quando os códigos expiram (padrão: 5 minutos)
- **Proteção contra Força Bruta**: Limite de 3 tentativas por código
- **Limpeza Automática**: Remove códigos expirados e logs antigos automaticamente
- **Template de Email Customizável**: Template próprio para os emails de verificação
- **Logs de Atividade**: Registra tentativas de login e atividades do módulo

## 🚀 Instalação

1. Faça o download do arquivo `securitybraza.php`
2. Copie o arquivo para o diretório `/modules/security/securitybraza/` do seu WHMCS
3. Acesse a área administrativa do WHMCS
4. Vá em **Sistema** → **Módulos de Segurança**
5. Localize "SecurityBraza 2FA Email" e clique em **Ativar**

## ⚙️ Configuração

Após a instalação, configure as seguintes opções:

- **Tempo de expiração do código (min)**: Define em quantos minutos o código expira (padrão: 5)
- **Tamanho do Código**: Número de dígitos do código de verificação (padrão: 6)

## 📧 Template de Email

O módulo cria automaticamente um template de email chamado "SecurityBraza: Email 2FA" que pode ser customizado em:
**Sistema** → **Templates de Email** → **SecurityBraza: Email 2FA**

### Variáveis disponíveis no template:
- `{$verification_code}` - O código de verificação
- `{$expiry_minutes}` - Tempo de expiração em minutos
- `{$ip_address}` - Endereço IP do usuário
- `{$timestamp}` - Data e hora da solicitação

## 🔧 Como Funciona

1. **Ativação**: O usuário ativa o 2FA em sua conta
2. **Login**: Ao fazer login, um código é enviado por email
3. **Verificação**: O usuário insere o código recebido
4. **Acesso**: Após verificação bem-sucedida, o login é completado

## 🛡️ Segurança

- Códigos são hasheados com SHA-256 + salt
- Proteção contra ataques de força bruta (máximo 3 tentativas)
- Códigos expiram automaticamente
- Limpeza automática de dados antigos via cron job

## 📊 Estrutura do Banco de Dados

O módulo cria duas tabelas:

- `mod_securitybraza_codes`: Armazena códigos de verificação temporários
- `mod_securitybraza_logs`: Registra logs de atividades

## 🔄 Versão

**Versão atual**: 1.1.1 - Correções de Bugs

## 📝 Requisitos

- WHMCS 7.0 ou superior
- PHP 7.4 ou superior
- Servidor de email configurado no WHMCS

## 🐛 Suporte

Para suporte, entre em contato:

**Discord**: @ianchamba

---

**Desenvolvido por SecurityBraza** 🇧🇷
