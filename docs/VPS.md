# VPS de Produção — Referência Rápida

> Quando alguém disser "acesse a VPS e veja", é aqui.
> **Sem segredos neste arquivo**: acesso por chave SSH; senhas ficam no `.env` (local e da VPS).
> Atualizado: Junho/2026.

---

## Acesso

```bash
ssh root@31.97.30.36          # chave ed25519 já autorizada (sem senha)
```

| Item | Valor |
|---|---|
| Painel | CyberPanel + LiteSpeed |
| PHP | `/usr/local/lsws/lsphp82/bin/php` (⚠️ não usar o `php` do PATH) |
| App | `/home/resolutiva.com.br/public_html/chat` |
| Usuário da app | `resol2813` |
| MySQL | local na VPS (`resolutivachat`); acesso remoto com user/senha do `.env` local do projeto |
| Supervisor conf | `/etc/supervisor/conf.d/agendativa.conf` (fonte no repo: `deploy/supervisor/agendativa.conf`) |

## ⚠️ Regras de ouro

**Nunca rodar `git`/`artisan` como root** — cria arquivos com dono root e quebra a app (já aconteceu com `laravel.log`). Sempre:

### 🔴 NUNCA rodar localmente apontando para produção

O `.env` local tem `DB_HOST=31.97.30.36` (produção). Comandos Artisan **ignoram** `--env=testing` para conexão de banco — só o PHPUnit respeita o `phpunit.xml`. Rodar qualquer comando destrutivo localmente **derruba produção**:

```bash
# ❌ PROIBIDO — derruba o banco de produção mesmo com --env=testing
php artisan migrate:fresh --env=testing
php artisan migrate:reset
php artisan db:wipe

# ✅ Para testes locais: criar .env.testing com SQLite
DB_CONNECTION=sqlite
DB_DATABASE=:memory:
# e rodar: php artisan migrate:fresh --env=testing  (só depois de ter .env.testing)
```

> Incidente 16/06/2026: `migrate:fresh --env=testing` rodado localmente dropou todas as tabelas de produção. Recuperado via backup de 15/06 às 21h.

```bash
cd /home/resolutiva.com.br/public_html/chat
runuser -u resol2813 -- /usr/local/lsws/lsphp82/bin/php artisan <comando>
runuser -u resol2813 -- git <comando>
```

Exceção atual: `git pull` precisa de root (só o root tem a chave do GitHub) — depois do pull, **sempre**:
```bash
chown -R resol2813:resol2813 /home/resolutiva.com.br/public_html/chat
```

## Diagnóstico rápido

```bash
supervisorctl status                                  # workers (sem sudo, já é root)
cd /home/resolutiva.com.br/public_html/chat
runuser -u resol2813 -- /usr/local/lsws/lsphp82/bin/php artisan queue:monitor mensagens,default,google_calendar,campanhas
runuser -u resol2813 -- /usr/local/lsws/lsphp82/bin/php artisan queue:failed | head -20
tail -50 storage/logs/laravel.log
tail -30 storage/logs/worker-rapida.log               # respostas do agente (fila mensagens)
tail -30 storage/logs/worker-lenta.log                # google_calendar / campanhas
tail -30 storage/logs/schedule.log                    # scheduler (cron, ainda como root)
```

## Workers (estado desde 11/06/2026)

| Program | Filas | Procs | Papel |
|---|---|---|---|
| `agendativa-fila-rapida` | `mensagens,default` | 2 | Respostas do agente (ProcessarMensagemOficialJob, ProcessarMensagemDebounceJob) |
| `agendativa-fila-lenta` | `google_calendar,campanhas,default` | 1 | Jobs lentos/retry longo |

Scheduler: **no Supervisor desde 11/06/2026** (`agendativa-scheduler`, `schedule:work` como `resol2813`). A linha antiga do crontab do root está **comentada** (`crontab -l` → linha com `#DESATIVADO-11/06`) — não descomentar, senão roda em dobro. Log: `storage/logs/scheduler.log`.

⚠️ Se apagar `scheduler.log`/`worker-*.log` manualmente, rodar `supervisorctl restart <program>` — o Supervisor mantém o arquivo aberto e continuaria escrevendo num inode deletado.

`laravel.log` **não** é rotacionado pelo Supervisor (não é stdout de um program) — desde 05/08/2026 usa `logrotate` do sistema (`/etc/logrotate.d/agendativa-laravel`, fonte em `deploy/logrotate/agendativa-laravel`), mesmos parâmetros dos workers (10MB, 3 backups), com `copytruncate` pra não quebrar os processos de longa duração que já têm o arquivo aberto (queue:work/schedule:work).

## Deploy

```bash
cd /home/resolutiva.com.br/public_html/chat
git pull                                              # como root (chave GitHub)
chown -R resol2813:resol2813 .                        # OBRIGATÓRIO após pull como root
runuser -u resol2813 -- /usr/local/lsws/lsphp82/bin/php artisan config:clear
runuser -u resol2813 -- /usr/local/lsws/lsphp82/bin/php artisan queue:restart   # senão workers rodam código antigo por até 1h
```

## Rollback dos workers

```bash
cp /root/agendativa.conf.bak-<data> /etc/supervisor/conf.d/agendativa.conf
supervisorctl reread && supervisorctl update
```
Backup existente: `/root/agendativa.conf.bak-2026-06-11-1507` (conf antigo, pré-migração).

## Backup / Disaster Recovery

- **MySQL roda na mesma VPS** e os backups do CyberPanel estão vazios → backup automático off-server ainda **PENDENTE (crítico)**.
- Backup-semente manual (11/06/2026) na máquina local de dev: `~/backups-agendativa/` (`prod.env.*` com a APP_KEY de produção + dump `resolutivachat-*.sql.gz`). **Nunca commitar esses arquivos.**
- Refazer dump manual (da máquina local, creds no `.env` local):
```bash
mysqldump -h 31.97.30.36 -u resolutivaclaude -p --single-transaction --no-tablespaces \
  --routines --triggers resolutivachat | gzip > ~/backups-agendativa/resolutivachat-$(date +%F).sql.gz
```
- ⚠️ A `APP_KEY` de produção é **diferente** da local e só existe na VPS + no backup local. Perdê-la = dados criptografados irrecuperáveis.

## Pendências conhecidas (janela calma)

1. Backup diário automático do MySQL off-server (**crítico**)
2. Deploy key do GitHub para `resol2813` (elimina pull como root + chown)
3. `php artisan schema:dump` versionado (repo não reconstrói schema — G11)
4. ~~Redis para queue/cache~~ ✅ feito 12/06/2026 (`CACHE_STORE=redis` + `QUEUE_CONNECTION=redis`) — Horizon segue opcional
5. Conferir onde vivem os uploads (`FILESYSTEM_DISK=s3` sem chaves `AWS_*` visíveis no .env de prod)

## Histórico

| Data | Mudança |
|---|---|
| 11/06/2026 | Workers separados por faixa (rapida×2/lenta×1), `retry_after=180`, jobs do agente → fila `mensagens`, `chown -R resol2813` no app, backup-semente de .env+banco |
| 11/06/2026 | Scheduler migrado cron→Supervisor (`schedule:work` como resol2813); linha do crontab comentada. Backup conf: `/root/agendativa.conf.bak-*-pre-scheduler` |
| 12/06/2026 | Cache e queue migrados para Redis (`CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `REDIS_CLIENT=phpredis`, eviction `noeviction`). Tabela `jobs` vazia na virada (sem drenagem). Backup: `.env.bak-redis-queue-20260612`. Rollback: reverter .env + `config:clear` + restart workers, drenar Redis com `queue:work redis --stop-when-empty` |
| 05/08/2026 | `laravel.log` sem histórico (driver `single`, nunca rotacionava — dificultou investigar bug de CSAT de 29/07). Adicionado `logrotate` (`/etc/logrotate.d/agendativa-laravel`, fonte `deploy/logrotate/agendativa-laravel`): 10MB/3 backups com `copytruncate`, espelhando os parâmetros do Supervisor pros workers |
