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
 "seller_name":"Ademir","device":"android 14 / a1b2c3","app_version":"2.0.8+44","db_version":12}
```

Só `role`, `token` e — para o vendedor — `seller_id` são obrigatórios. O resto tem default e
serve para o operador saber com quem está falando.

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

> **Ponto de atenção no deploy:** o balanceador do Jelastic (nginx) costuma cortar conexão ociosa
> em 60–75 s. Os 30 s de ping ficam abaixo disso, mas confirme o valor real do ambiente — é a
> falha mais comum desse tipo de deploy, e ela aparece só de madrugada, sem tráfego.

## 6. Autenticação

Token global único em `RELAY_TOKEN` (arquivo `.env`), comparado com `hash_equals` — comparação em
tempo constante, para não vazar o segredo por timing.

> **Limitação conhecida e aceita:** com um token só, quem o tiver pode conectar como
> `role: "panel"` e ler o banco de qualquer vendedor. Dois tokens (um por papel) resolveriam com
> uma linha a mais em `Handshake::authenticate()`. Se isso mudar, o campo `role` já existe e o
> lugar da mudança é um só.

`RELAY_SELLER_ALLOWLIST` (opcional, `seller_id` separados por vírgula) restringe quais vendedores
podem conectar. Vazio libera todos.

## 7. Operação

```bash
composer install
php bin/relay.php start      # primeiro plano, para ver o log
php bin/relay.php start -d   # daemon
php bin/relay.php status
php bin/relay.php stop
```

Variáveis em `.env`:

```
RELAY_TOKEN=<segredo compartilhado pelas duas pontas>
RELAY_PORT=8443
RELAY_SELLER_ALLOWLIST=
```

Apache, para publicar o endpoint no mesmo domínio e reaproveitar o certificado:

```apache
# habilitar: mod_proxy, mod_proxy_http, mod_proxy_wstunnel
ProxyPass        /relay ws://127.0.0.1:8443/
ProxyPassReverse /relay ws://127.0.0.1:8443/
ProxyTimeout     300
```

No Jelastic, o processo precisa subir junto com o nó — pelo supervisor do ambiente ou por um
entry point que rode `php bin/relay.php start -d`. Ele **não** roda dentro do Apache: o modelo
request/response do mod_php/FPM não sustenta conexão longa.

Log em `storage/logs/relay_{Y-m-d}.log`, no mesmo padrão dos logs de DANFE e imagem.
