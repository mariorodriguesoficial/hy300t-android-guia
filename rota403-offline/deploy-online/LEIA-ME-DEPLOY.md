# Preparar o servidor online (https://mrinformatica.inf.br/rota403) para sincronizar com o PDV

**Isto altera o banco de PRODUÇÃO.** Faça num horário de pouco movimento e só depois de ter backup. Eu (o assistente) não tenho acesso à hospedagem: nada disto foi aplicado lá.

O pacote `rota403-sync-online.zip` contém **somente arquivos novos** (não substitui nenhum arquivo do sistema atual): `includes/{Migrator,Sync,SyncSpec,SyncServer}.php`, `migrations/`, `api/sync/v1/`, `bin/` e `sync-setup-web.php`. Foi testado contra o código **v23 original** (21 testes de sincronização, `ONLINE_V23=1 tests/run_sync.sh`), não contra uma instalação real da Hostinger.

## 0. Antes de tudo
1. **Backup do banco**: phpMyAdmin → Exportar → "Personalizado", formato SQL, marcar estruturas e dados → baixe e guarde.
2. **Backup dos arquivos**: Gerenciador de Arquivos → compacte a pasta `rota403` e baixe.
3. **Teste se o banco aceita triggers** (phpMyAdmin → SQL). Deve rodar sem erro:
```sql
CREATE TABLE zz_teste_trg (id INT);
CREATE TRIGGER zz_trg BEFORE INSERT ON zz_teste_trg FOR EACH ROW SET NEW.id = 1;
DROP TRIGGER zz_trg;
DROP TABLE zz_teste_trg;
```
Se aparecer `Access denied ... TRIGGER` ou erro 1419 (`SUPER privilege and binary logging`), **PARE**: a hospedagem não permite triggers e a sincronização precisa de outro desenho. Me avise.

## 1. Enviar os arquivos
Gerenciador de Arquivos → pasta `public_html/rota403` → **Upload** do zip → **Extrair** ali mesmo. Não apague nada. Não mexa em `config/config.php` nem `config/chave_seguranca.php` além do passo 2.

## 2. Três linhas no `config/config.php`
Abra `config/config.php` (faça cópia antes) e acrescente **no final**, depois dos `require_once` existentes:
```php
require_once BASE_PATH . '/includes/Migrator.php';
require_once BASE_PATH . '/includes/Sync.php';
require_once BASE_PATH . '/includes/SyncServer.php';
```

## 3. Bloquear `bin/` e `migrations/` no `.htaccess`
No `.htaccess` da pasta `rota403`, troque a linha
```
RewriteRule ^(config|includes|tests|QZ_WINDOWS|ATALHO_CHROME)/ - [F,L,NC]
```
por
```
RewriteRule ^(config|includes|tests|bin|migrations|storage|QZ_WINDOWS|ATALHO_CHROME)/ - [F,L,NC]
```
(Os scripts de `bin/` também se recusam a rodar pela web, mas bloqueie nos dois níveis.)

## 4. Aplicar migrações, dados iniciais e criar o dispositivo
**Opção A — com SSH (recomendada)**
```bash
cd ~/domains/mrinformatica.inf.br/public_html/rota403      # ajuste o caminho
php bin/migrate.php
php bin/sync-admin.php semear
php bin/sync-admin.php device-add "PDV Balcao"
```
O último comando mostra `device_id` e `secret` **uma vez**. Guarde-os.

**Opção B — sem SSH (`sync-setup-web.php`)**
1. Edite o arquivo no servidor e troque `TOKEN` por 40+ caracteres aleatórios (anote).
2. Abra `https://mrinformatica.inf.br/rota403/sync-setup-web.php`, informe o token e execute **nesta ordem**: *Ver estado* → *Aplicar migrações* → *Enfileirar dados existentes* → *Criar dispositivo* (mostra o secret uma vez e **apaga o arquivo**). Confirme que o arquivo sumiu.

## 5. Conferir o online
- `https://mrinformatica.inf.br/rota403/api/sync/v1/health` → `{"ok":true,"api":1,"esquema":true}`
- `https://mrinformatica.inf.br/rota403/bin/migrate.php` → **403**
- Entre no sistema online normalmente e lance um pedido de teste: tudo deve funcionar como antes.

## 6. Ligar o PDV local
No Windows do PDV (PowerShell como Administrador):
```powershell
powershell -ExecutionPolicy Bypass -File "C:\Program Files\Rota403\windows\configurar-sync.ps1" -DeviceId <device_id>
```
Cole o `secret` quando pedir. O script baixa o pacote de certificados (conferindo o hash), testa o `health`, grava a configuração e roda a primeira sincronização.

## 7. Como voltar atrás
- **Desligar só o PDV**: `Stop-Service Rota403Sync` (o online continua funcionando; apenas acumula fila).
- **Reverter o online**: restaure o backup do banco do passo 0 e remova os arquivos novos. (As migrações não têm "desfazer" automático.)

## Riscos conhecidos
- O primeiro `ALTER TABLE` pode travar brevemente as tabelas durante a migração.
- Se o servidor online perder o arquivo `config/chave_seguranca.php`, os segredos dos dispositivos deixam de ser legíveis (gere um novo dispositivo).
- Usuários **não** sincronizam: o PDV tem os próprios usuários.
