# Relay de suporte remoto — contrato

Este documento é a fonte da verdade do canal. Três projetos dependem dele:

| Papel | Projeto | Conecta como |
|---|---|---|
| Relay | `hubapp` (este) | servidor WebSocket |
| Painel de suporte | `el_monitor_apps` (Flutter macOS) | cliente, `role: "panel"` |
| App do vendedor | `adm_vendas` / `adm_pedidos` (Flutter Android) | cliente, `role: "seller"` |

Antes existia conexão TCP/TLS direta entre painel e app, o que exigia port-forward, DDNS e
certificado renovado no Mac. Agora as duas pontas são clientes deste relay: o celular está atrás
do NAT da operadora e o Mac atrás do roteador do escritório, mas os dois conseguem abrir uma
conexão de saída para um servidor público.

O relay é deliberadamente burro. Ele autentica, sabe quem está conectado e repassa mensagens.
Ele **não** interpreta `query`, `correction` nem conhece sembast — esse contrato é negociado
entre painel e app, e está documentado em `docs/protocol.md` do `el_monitor_apps`.

---

## 1. Transporte

- WebSocket, texto, uma mensagem JSON por frame (objeto no topo, sempre).
- Endpoint público: `wss://<domínio>/relay`, servido pelo Apache via `mod_proxy_wstunnel`
  apontando para `ws://127.0.0.1:8443`. O TLS é o do domínio, terminado no proxy do Jelastic —
  não há certificado próprio nem pinning em nenhum cliente.
- Frame acima de **1 MiB** é descartado e registrado. O limite é do Workerman e do `memory_limit`
  do PHP; por isso o `limit` de paginação do `query` é capado em 200 documentos nas duas pontas.
- Frame que não decodifica como objeto JSON é descartado e registrado. **A conexão nunca cai por
  causa de uma mensagem malformada** — derrubar a conexão do painel tiraria todos os vendedores
  do ar de uma vez.

## 2. Handshake

A primeira mensagem depois do upgrade tem que ser o `hello`, dentro de **10 segundos**. Quem não
se identificar no prazo é desconectado — é o que impede um scanner de porta de segurar recurso.

Painel:

```json
{"type":"hello","role":"panel","token":"<RELAY_TOKEN>","panel_id":"mac-suporte-01"}
```

Vendedor:

```json
{"type":"hello","role":"seller","token":"<RELAY_TOKEN>","seller_id":"V0143",
 "seller_name":"Ademir","tenant":"48707144000109","device":"android 14 / a1b2c3",
 "app_version":"2.0.8+44","db_version":12}
```

Só `role`, `token` e — para o vendedor — `seller_id` são obrigatórios. O resto tem default e
serve para o operador saber com quem está falando. O `tenant` é a empresa do vendedor, e é
o que localiza os backups dele em `storage/backups/{cnpj}/{sellerId}/`.

> **`seller_id` é um endereço de aparelho, não o código do vendedor.** A regra de substituição
> (seção 4) faz dele um endereço: o que atende nele é um aparelho, e só um. O mesmo vendedor
> pode trabalhar em dois celulares — um Android e um iPhone —, e se os dois anunciarem `V0143`
> o segundo derruba o primeiro. Por isso o app compõe `"<código do vendedor>~<id da instalação>"`
> (`V0143~6DA5AB97…`), e o relay não precisa fazer nada a respeito: indexar por `seller_id` já
> está certo quando o endereço é um por aparelho. O que **precisa** de atenção é a
> `RELAY_SELLER_ALLOWLIST` — ela compara o `seller_id` inteiro, então listar `V0143` ali passa a
> barrar os dois aparelhos do Ademir. Hoje está vazia, o que libera todos.

> **Campo novo no `hello` precisa entrar em `Handshake::inspectSeller` também.** O relay
> repassa uma lista fixa de campos — repassar o objeto inteiro deixaria o app escrever
> qualquer coisa na identidade que o painel confia. O preço é que um campo esquecido ali
> some no caminho, sem erro e sem aviso.

Resposta ao painel, com o snapshot de presença:

```json
{"type":"welcome","role":"panel","panel_id":"mac-suporte-01",
 "sellers":[{"seller_id":"V0143","seller_name":"Ademir","device":"…","app_version":"…","db_version":12}]}
```

Resposta ao vendedor:

```json
{"type":"welcome","role":"seller","seller_id":"V0143"}
```

O snapshot é **autoritativo**: o painel adiciona os que faltam na sua lista e fecha os que sobram.
Sem ele, um `seller_offline` perdido numa queda de rede viraria vendedor fantasma na tela.

### Códigos de fechamento

| Código | Significado | Cliente deve tentar de novo? |
|---|---|---|
| 1000 | encerramento normal | — |
| 1006 | queda de conexão | sim, com backoff |
| 4000 | `hello` malformado ou fora do prazo | sim |
| 4001 | token inválido | **não** — pede ação humana |
| 4003 | `role` desconhecida | **não** |
| 4009 | `panel_id` já conectado | **não** |

## 3. Presença (relay → painel)

```json
{"type":"seller_online","seller_id":"V0143","hello":{"seller_id":"V0143","seller_name":"Ademir","device":"…","app_version":"…","db_version":12}}
{"type":"seller_offline","seller_id":"V0143","reason":"timeout"}
```

`reason` é `closed` (o app fechou), `timeout` (heartbeat estourou) ou `replaced` (outro `hello`
com o mesmo `seller_id` chegou — a conexão anterior é fechada).

São esses eventos que criam e destroem sessão no painel. Não existe mais "socket aberto" como
prova de que alguém está online.

## 4. Roteamento

### Painel → vendedor

O painel envelopa a mensagem do protocolo de aplicação:

```json
{"seller_id":"V0143","payload":{"type":"query","req_id":"a17","store":"pedidos","limit":50}}
```

O relay entrega o `payload` cru ao vendedor — o app recebe exatamente o que o painel escreveu,
sem envelope. Se o vendedor não estiver conectado, o relay responde ao painel que enviou:

```json
{"seller_id":"V0143","payload":{"type":"error","req_id":"a17","error":"Vendedor não está conectado."}}
```

### Vendedor → painel

O vendedor manda a mensagem crua; o relay envelopa e entrega:

```json
{"seller_id":"V0143","payload":{"type":"result","req_id":"a17","documents":[…],"total":2}}
```

A entrega vai para **a conexão de painel que originou aquele `req_id`**, nunca por broadcast. O
relay guarda `req_id → painel` com TTL de 120 s. Com dois painéis abertos, um broadcast faria os
dois completarem o mesmo `req_id`, duplicando o registro de auditoria.

Mensagem sem `req_id` conhecido (`pong`, ou um `error` espontâneo) vai para todos os painéis.

### Antispoofing

O `seller_id` que chega ao painel é **sempre** o da sessão autenticada, escrito pelo relay no
envelope. Qualquer `seller_id` que venha dentro do `payload` do vendedor é ignorado pelo painel.
Por isso o relay envelopa (`{seller_id, payload}`) em vez de injetar o campo por merge: um merge
deixaria o vendedor sobrescrever o campo e se passar por outra loja.

## 5. Heartbeat

O relay manda `{"type":"ping"}` para cada ponta a cada **30 s** e espera `{"type":"pong"}`.
Nenhum tráfego por **90 s** (três janelas) fecha a conexão; no caso de um vendedor, isso emite
`seller_offline` com `reason: "timeout"`.

Ping de aplicação, e não só o frame de controle do WebSocket, porque um proxy intermediário pode
responder o controle sem que o processo do relay esteja vivo.

**O painel também pinga**, pelo mesmo intervalo e por outro motivo: é o relay que derruba quem
fica calado por três janelas, e um painel ocioso não tem mais nada a dizer. O relay responde
esses pings com `{"type":"pong"}` — e é essa resposta que dá ao painel a prova de que o processo
daqui está vivo, pelo mesmo argumento do parágrafo acima.

As duas mensagens vão nuas, fora do envelope `{seller_id, payload}`: são endereçadas à outra
ponta, não a um vendedor. Cada lado precisa de um caso próprio para o `pong` nu — sem ele a
mensagem cai no caminho do envelope e vira uma linha de descarte a cada 30 segundos, que enterra
o log de verdade.

> **Ponto de atenção no deploy:** o balanceador do Jelastic (nginx) costuma cortar conexão ociosa
> em 60–75 s. Os 30 s de ping ficam abaixo disso, mas confirme o valor real do ambiente — é a
> falha mais comum desse tipo de deploy, e ela aparece só de madrugada, sem tráfego.

## 6. Autenticação

Token global único em `RELAY_TOKEN` (variável de ambiente), comparado com `hash_equals` — comparação em
tempo constante, para não vazar o segredo por timing.

> **Limitação conhecida e aceita:** com um token só, quem o tiver pode conectar como
> `role: "panel"` e ler o banco de qualquer vendedor. Dois tokens (um por papel) resolveriam com
> uma linha a mais em `Handshake::authenticate()`. Se isso mudar, o campo `role` já existe e o
> lugar da mudança é um só.

`RELAY_SELLER_ALLOWLIST` (opcional, `seller_id` separados por vírgula) restringe quais vendedores
podem conectar. Vazio libera todos.

## 7. Backups dos vendedores (HTTP, fora do relay)

O app guarda cópias do banco no próprio aparelho — uma por dia, uma antes de cada
intervenção do suporte, e as que o vendedor pedir. Aquilo resolve desfazer uma correção.
**Não resolve o celular sumir**: desinstalar o app, formatar, perder, quebrar, trocar de
aparelho. Justamente quando o backup mais importa. Por isso as cópias também sobem para cá.

Vai por HTTP, e não pelo relay: o relay carrega comandos curtos com teto de 1 MiB por
frame, e um banco é transferência de arquivo. Três rotas, todas autenticadas com o mesmo
`RELAY_TOKEN` no header `Authorization: Bearer`:

| Método | Rota |
|---|---|
| POST | `/public/backups/{cnpj}/{sellerId}/upload` |
| GET | `/public/backups/{cnpj}/{sellerId}` |
| GET | `/public/backups/{cnpj}/{sellerId}/download/{file}` |

O upload é `multipart/form-data` com o arquivo em `backup` e os metadados em campos
(`file`, `created_at`, `origin`, `documents`, `original_bytes`, `stores`).

**Chega comprimido.** Um export de 6 MB vira menos de 1 MB em gzip, e quem paga a
diferença é o plano de dados do vendedor. O painel descomprime ao baixar, porque o
aparelho espera o `.jsonl` de volta.

**Retenção por origem**, como no aparelho: 7 automáticos, 3 manuais, 3 de correção, 2 de
restauração — por vendedor. Um limite único faria uma sequência de correções apagar os
diários da semana, justamente as cópias que salvam quando a correção foi a causa.

Os arquivos vão para `storage/backups/{cnpj}/{sellerId}/`, que precisa de volume
persistente no Jelastic como o resto do `storage/`. Com ~600 KB por cópia e 30 vendedores,
a retenção acima ocupa algo perto de 180 MB.

> Estes são os únicos endpoints autenticados do servidor. Os demais nasceram sem
> autenticação nenhuma — problema separado, que continua de pé.

## 8. Operação

### Requisitos de PHP

O relay é um processo de linha de comando que fica no ar indefinidamente, e isso pede do
PHP coisas que uma página web não pede:

| Item | Por quê | Como conferir |
|---|---|---|
| `pcntl` e `posix` | O Workerman não sobe sem as duas | `php -m \| grep -E 'pcntl\|posix'` |
| `disable_functions` vazio | `pcntl_fork`, `pcntl_signal` e `posix_kill` precisam estar liberados | `php -i \| grep disable_functions` |
| `$argv` disponível | É como `start`/`stop`/`status` chegam ao script | `php -r 'var_dump($argv);' start` |
| `memory_limit` ≥ 256M | O processo não recicla entre requisições, como o Apache faz | `php -d memory_limit=256M …` no start |

No ambiente Jelastic atual (setembro de 2026) as três primeiras já vêm assim no `php.ini`
padrão — `extension=pcntl.so` e `extension=posix.so` descomentadas, `disable_functions`
vazio. O `register_argc_argv = 0` do arquivo **não** atrapalha: o SAPI do CLI força essa
diretiva para `1`, junto com `max_execution_time = 0`, e nenhuma das duas pode ser mudada
pelo `php.ini`. Confirme com `php --ini` que o CLI lê o arquivo que você está olhando.

`event.so` fica comentado por padrão. Sem ele o Workerman usa `stream_select`, cujo teto é
de 1024 descritores — folgado para dezenas de vendedores. Só vale habilitar (o módulo já
existe no Jelastic) se as conexões simultâneas passarem de algumas centenas.

### Subir

```bash
composer install

php bin/relay.php start                            # primeiro plano, para ver o log
php -d memory_limit=256M bin/relay.php start -d    # daemon
php bin/relay.php status
php bin/relay.php stop
```

Ele **não** roda dentro do Apache: o modelo request/response do mod_php/FPM não sustenta
conexão longa. E ele morre num restart do nó se nada o reiniciar — no Jelastic, o caminho
usual é um `@reboot` no cron do nó ou um serviço systemd.

Suba a primeira vez em primeiro plano, sem `-d`. O log vai para a tela e um erro de
extensão ou de porta aparece na hora, em vez de sumir num arquivo.

Variáveis de ambiente:

```
RELAY_TOKEN=<segredo compartilhado pelas duas pontas>
RELAY_PORT=8443
RELAY_SELLER_ALLOWLIST=
```

Localmente elas ficam no `.env` (não versionado). **Em produção não existe `.env`**: as
variáveis do site vêm das *Variables* do nó no painel do Jelastic — mas essas só chegam ao
processo do `httpd`, não à sessão SSH nem ao cron. Como o relay é um processo CLI, ele
precisa da própria fonte: um arquivo fora do webroot, com permissão `600`, carregado no
comando de start.

```bash
# /home/jelastic/hubapp-relay.env  (chmod 600, fora do webroot)
RELAY_TOKEN=...
RELAY_PORT=8443
RELAY_SELLER_ALLOWLIST=

# start (use esta linha também no @reboot do cron ou no ExecStart do systemd)
set -a; . /home/jelastic/hubapp-relay.env; set +a; \
  php -d memory_limit=256M /var/www/webroot/ROOT/bin/relay.php start -d
```

O `RELAY_TOKEN` tem de ser o mesmo valor nos dois lugares — as Variables do nó (usadas pelo
`BackupController`) e esse arquivo (usado pelo relay).

### Publicar o `/relay`

O relay escuta em `127.0.0.1:8443`, e o Apache publica isso no mesmo domínio — assim o
`wss://` reaproveita o certificado que já existe, e nenhuma porta nova é exposta.

`ProxyPass` **não é permitido em `.htaccess`**. Ou vai no vhost, pelo config manager do
Jelastic:

```apache
# habilitar: mod_proxy, mod_proxy_http, mod_proxy_wstunnel
ProxyPass        /relay ws://127.0.0.1:8443/
ProxyPassReverse /relay ws://127.0.0.1:8443/
ProxyTimeout     300
```

…ou, sem acesso ao vhost, pelo `.htaccess` com a flag `[P]` do `mod_rewrite`, que aceita
proxy onde o `ProxyPass` não é permitido:

```apache
RewriteCond %{HTTP:Upgrade} =websocket [NC]
RewriteRule ^relay$ ws://127.0.0.1:8443/ [P,L]
```

### Depois de subir

1. **Teste de fumaça**, do repositório do painel:

   ```bash
   dart tool/simulated_seller.dart --relay wss://<domínio>/relay --token "$RELAY_TOKEN"
   ```

   Um `← welcome` na tela prova o caminho inteiro: proxy, upgrade, handshake e token.

2. **Deixe 20 minutos ocioso.** É o teste que expõe o balanceador cortando conexão parada
   antes do heartbeat de 30 s chegar lá. Falha só de madrugada, sem tráfego, e por isso
   passa despercebida se não for procurada de propósito.

3. **Confirme que `storage/` está em volume persistente.** Já valia para os PDFs e as
   imagens; o log do relay só acrescenta. Sem volume, tudo se perde a cada redeploy.

Log em `storage/logs/relay_{Y-m-d}.log`, no mesmo padrão dos logs de DANFE e imagem.
