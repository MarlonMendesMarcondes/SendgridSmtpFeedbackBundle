# SendGrid Callback Bundle

Plugin do Mautic 5 que trata, no **`/mailer/callback`** do próprio Mautic, os eventos do
SendGrid Event Webhook (bounce, dropped, spam e descadastro) e marca o contato como
**Não Contatar**.

Funciona com o envio por **SMTP** (`smtp.sendgrid.net`), sem trocar o transporte nem
instalar nada pelo composer.

## Por que existe

No Mautic 5, o `/mailer/callback` não sabe nada de provedor. Ele só dispara o evento
`mautic.email.on_transport_webhook` e espera que o transporte de e-mail responda
(`app/bundles/EmailBundle/Controller/PublicController.php`, `mailerCallbackAction`).
Quando o envio é por SMTP, nenhum transporte responde, e o Mautic devolve:

```
404 - No email transport that could process this callback was found
```

Era isso que acontecia nas instâncias Mautic 5 que recebem, pelo n8n, os eventos do
SendGrid Event Webhook. As instâncias Mautic 4 não têm o problema: elas usam
`/mailer/sendgrid_api/callback`.

Pacotes que **não** resolvem:

- **`composer require symfony/sendgrid-mailer`**: no Mautic 5 (Symfony 5.4) o pacote
  só traz o transporte de envio (`sendgrid+api://`, `sendgrid+smtp://`). O leitor de
  webhook (`SendgridRequestParser`) só existe a partir do Symfony 6.4, e mesmo nele é
  feito para o componente Symfony Webhook, que o Mautic não usa.
- **Plugins da comunidade** (`azlobin/mautic-sendgrid-callback`,
  `tvalimaa/mautic-bounce-webhook-bundle`): têm poucas instalações e não foram
  validados no Mautic 5.2.

## Como funciona

1. **Recebe o callback.** O n8n faz POST no `/mailer/callback` com a lista de eventos
   do SendGrid, sem alterar o corpo.
2. **Reconhece o corpo do SendGrid.** O subscriber
   (`EventListener/SendgridCallbackSubscriber.php`) só aceita um JSON que seja uma lista
   de eventos, cada um com `event` e `email`, e pelo menos um com `sg_event_id`.
   Qualquer outro corpo fica para outro transporte, ou para o 404 do core.
3. **Confere o token.** O token vem no header `X-Mautic-Callback-Token` ou no parâmetro
   `?token=` e precisa ser igual a `sendgrid_callback_token`.
4. **Marca Não Contatar.** Para cada evento, `Service/SendgridEventMapper.php` decide a
   ação, e o plugin chama `TransportCallback::addFailureByAddress()`, o mesmo serviço
   que os transportes do core usam. Todos os contatos com aquele e-mail são marcados.
5. **Responde 200** com a contagem:
   ```json
   {"processed": 1, "ignored": 2, "failed": 0}
   ```
   Se um evento der erro, ele é contado em `failed`, o erro vai para o log e os outros
   eventos continuam sendo processados.

### O que cada evento vira

| Evento SendGrid | Motivo | Resultado |
|---|---|---|
| `bounce` (`type` = `bounce` ou ausente) | qualquer | **Bounced** |
| `bounce` com `type` = `blocked` | recusa temporária | ignorado |
| `dropped` | `Bounced Address`, `Invalid` | **Bounced** |
| `dropped` | `Unsubscribed Address`, `Spam Reporting Address` | **Unsubscribed**\* |
| `spamreport`, `unsubscribe`, `group_unsubscribe` | | **Unsubscribed**\* |
| `delivered`, `open`, `click`, `deferred`, `processed` e outros `dropped` | | ignorado |

\* Só quando `sendgrid_callback_process_unsubscribes` = `true`. Veja o cuidado abaixo.

O comentário do Não Contatar fica `SendGrid <evento>: <motivo>`. Por exemplo:
`SendGrid dropped: Bounced Address`.

### Respostas

| HTTP | Quando |
|---|---|
| 200 | Corpo do SendGrid com token válido. Os eventos foram processados. |
| 401 | Corpo do SendGrid com token ausente ou errado. Nada foi marcado. |
| 503 | `sendgrid_callback_token` não configurado. O plugin recusa tudo. |
| 404 | O corpo não é do SendGrid e nenhum outro transporte respondeu (resposta do core). |

## Instalação (em cada instância Mautic 5)

1. **Copie a pasta** `SendgridCallbackBundle` para `plugins/` da instância.
2. **Configure o token** em `app/config/local.php`:
   ```php
   'sendgrid_callback_token' => '<token longo e aleatório>',
   // 'sendgrid_callback_process_unsubscribes' => false, // padrão
   ```
   Para gerar um token: `openssl rand -hex 32`.
3. **Limpe o cache e recarregue os plugins**, como o usuário do servidor web:
   ```bash
   php bin/console cache:clear
   php bin/console mautic:plugins:reload
   ```
   O plugin não tem tela de configuração nem integração para ativar: o subscriber
   passa a responder assim que o bundle carrega.
4. **Teste:**
   ```bash
   curl -s -X POST https://<instancia>/mailer/callback \
     -H 'Content-Type: application/json' \
     -H 'X-Mautic-Callback-Token: <token>' \
     -d '[{"email":"teste-bounce@exemplo.com","event":"dropped","reason":"Bounced Address","sg_event_id":"teste-1"}]'
   ```
   O esperado é `{"processed":1,"ignored":0,"failed":0}`. Sem o header, a resposta deve
   ser `401`. Se o e-mail de teste não existir na instância, nada é marcado, mas o
   evento conta como `processed` mesmo assim.

## Configuração no n8n

Use a URL `https://<instancia>/mailer/callback` e envie o token no header.

1. Crie uma credencial **Header Auth** com:
   - **Name:** `X-Mautic-Callback-Token`
   - **Value:** o token
2. No nó **HTTP Request** que faz o POST, em **Authentication**, escolha **Generic
   Credential Type → Header Auth** e selecione essa credencial.

Assim o token não aparece no JSON do workflow. Se cada instância tiver o seu token,
guarde-o junto com a URL da instância e mande-o como header pelo `{{ $json.token }}`.

Para instâncias **Mautic 4**, mantenha a URL `/mailer/sendgrid_api/callback`. Este
plugin é só para o Mautic 5.

## Cuidado: descadastro e spam

Quando um único webhook do SendGrid atende vários clientes, é comum o n8n mandar
**todos os eventos para todas as instâncias**. Bounce é um problema do endereço: ele é
inválido para qualquer cliente, então marcar em todas as instâncias está certo.

Descadastro e spam são diferentes: valem só para o cliente que enviou. Com
`sendgrid_callback_process_unsubscribes` = `true`, um descadastro na instância A marca
Não Contatar o mesmo e-mail nas instâncias B, C, D... Por isso o padrão é `false`. Só
ligue quando o envio identificar a instância de origem, por exemplo com um `custom_args`
no SendGrid, e o n8n mandar cada evento só para a instância que enviou.

## Estrutura do projeto

```
SendgridCallbackBundle/
├── SendgridCallbackBundle.php                      classe do bundle (PluginBundleBase, vazia)
├── Config/
│   ├── config.php                                  nome, versão e parâmetros padrão
│   └── services.php                                registro dos serviços (autowire + autoconfigure)
├── DependencyInjection/
│   └── SendgridCallbackExtension.php               carrega o Config/services.php
├── EventListener/
│   └── SendgridCallbackSubscriber.php              entrada HTTP: reconhece o corpo, token, Não Contatar
├── Service/
│   └── SendgridEventMapper.php                     regra: evento SendGrid → motivo do Não Contatar
├── Tests/Unit/
│   ├── EventListener/SendgridCallbackSubscriberTest.php
│   └── Service/SendgridEventMapperTest.php
├── phpunit.xml                                     suíte unit (roda no container)
├── phpstan.neon                                    análise estática, nível 8
├── composer.json                                   metadados do pacote (type mautic-plugin)
├── CHANGELOG.md                                    um bloco por dia, do mais novo para o mais antigo
└── README.md
```

O plugin **não tem** rota, controller, entidade, migration, tela, tradução nem
integração. Por isso não há nada para ativar em Configurações > Plugins, e o
`mautic:plugins:reload` só registra o plugin na lista.

### Como as peças se ligam

```
Kernel do Mautic
  └─ SendgridCallbackBundle            (descoberto em plugins/)
       └─ SendgridCallbackExtension    (achada pela convenção de nome do Symfony)
            └─ Config/services.php     (load() da pasta toda, menos DEFAULT_EXCLUDES)
                 ├─ SendgridEventMapper
                 └─ SendgridCallbackSubscriber  ← autoconfigure: vira listener de
                                                   mautic.email.on_transport_webhook

POST /mailer/callback
  └─ PublicController::mailerCallbackAction (core)
       └─ dispatch EmailEvents::ON_TRANSPORT_WEBHOOK (TransportWebhookEvent)
            └─ SendgridCallbackSubscriber::onTransportWebhook()
                 ├─ resposta já definida por outro transporte? → sai sem fazer nada
                 ├─ extractSendgridEvents() → null? → sai (core devolve 404)
                 ├─ token vazio → 503 | token errado → 401
                 └─ para cada evento:
                      SendgridEventMapper::map() → null → ignored
                                                 → {reason, comment}
                      TransportCallback::addFailureByAddress() → processed | failed
```

Como o plugin tem `Config/services.php`, o loader global do Mautic **não** registra
os serviços dele. Quem carrega é a `SendgridCallbackExtension`. Se um dia ela for
removida, o `services.php` precisa sair junto, e vice-versa.

### Classes

**`EventListener/SendgridCallbackSubscriber`**: a única porta de entrada.
- `getSubscribedEvents()`: escuta `EmailEvents::ON_TRANSPORT_WEBHOOK`, prioridade 0.
- `onTransportWebhook(TransportWebhookEvent)`: faz o fluxo acima e define a
  `JsonResponse` no evento. Nunca lança exceção para fora: a falha de um evento é
  registrada no log e contada em `failed`.
- `extractSendgridEvents(Request)` (privado): reconhece o corpo do SendGrid. Aceita
  uma lista JSON não vazia em que **todo** item tem `event` e `email` como string, e
  **pelo menos um** tem `sg_event_id`. Se o corpo não bate, devolve `null`.
- `givenToken(Request)` (privado): lê o header `X-Mautic-Callback-Token`; se ele
  faltar, lê `?token=`. A comparação é feita com `hash_equals`.
- Constantes públicas: `TOKEN_HEADER` e `TOKEN_QUERY`.
- Dependências, injetadas por autowire: `TransportCallback`, `CoreParametersHelper`,
  `SendgridEventMapper` e `LoggerInterface`.

**`Service/SendgridEventMapper`**: regra pura, sem I/O. Fica separada para ser
testada sem mock.
- `map(array $event, bool $processUnsubscribes): ?array` devolve
  `['reason' => DoNotContact::BOUNCED|UNSUBSCRIBED, 'comment' => string]`, ou `null`
  quando o evento deve ser ignorado.
- `event`, `type` e `reason` são comparados sem diferenciar maiúsculas de minúsculas.
- O comentário é cortado em 255 caracteres. A coluna `lead_donotcontact.comments` é
  `text`, então o corte serve só para manter a tela de Não Contatar legível.
- As listas de motivos ficam nas constantes `DROPPED_BOUNCE_REASONS`,
  `DROPPED_UNSUBSCRIBE_REASONS` e `UNSUBSCRIBE_EVENTS`.

### Parâmetros

Os valores padrão ficam em `Config/config.php`. Cada instância sobrescreve no seu
`app/config/local.php`.

| Parâmetro | Padrão | Efeito |
|---|---|---|
| `sendgrid_callback_token` | `''` | Token exigido no callback. Vazio = responde 503 a todo corpo do SendGrid. |
| `sendgrid_callback_process_unsubscribes` | `false` | Liga o tratamento de descadastro e spam (ver "Cuidado: descadastro e spam"). |

### O que o plugin usa do core

| Do core | Para quê |
|---|---|
| `EmailEvents::ON_TRANSPORT_WEBHOOK` e `TransportWebhookEvent` | Ganchos do `/mailer/callback` (`EmailBundle`). |
| `TransportCallback::addFailureByAddress()` | Acha **todos** os contatos com o e-mail e grava o Não Contatar no canal `email`. É o mesmo caminho dos transportes nativos. |
| `DoNotContact::BOUNCED` (2) e `DoNotContact::UNSUBSCRIBED` (1) | Motivos gravados em `lead_donotcontact.reason`. |
| `CoreParametersHelper` | Lê os parâmetros acima. |
| `MauticCoreExtension::DEFAULT_EXCLUDES` | Pastas que o `services.php` não registra como serviço (`Tests`, `Config` etc.). |

Na migração para o Mautic 6 ou 7, confira primeiro se esses nomes mudaram.

### Onde mexer

| Quero... | Arquivo |
|---|---|
| tratar um novo evento ou motivo do SendGrid | `Service/SendgridEventMapper.php` + `SendgridEventMapperTest` |
| mudar a forma de reconhecer o corpo ou o token | `EventListener/SendgridCallbackSubscriber.php` + `SendgridCallbackSubscriberTest` |
| mudar o formato da resposta HTTP | `SendgridCallbackSubscriber::onTransportWebhook()` |
| criar um parâmetro novo | padrão em `Config/config.php`, leitura via `CoreParametersHelper` |
| publicar uma versão | `version` em `Config/config.php` + bloco do dia no `CHANGELOG.md` |

### Testes

- `SendgridEventMapperTest`: um caso para cada linha da tabela "O que cada evento
  vira", com a opção de descadastro ligada e desligada, e o corte do comentário.
- `SendgridCallbackSubscriberTest`: o registro no evento; o token (header, query,
  errado, ausente, não configurado); os corpos que não são do SendGrid (vazio, JSON
  inválido, lista vazia, objeto, sem `sg_event_id`, item sem e-mail); a resposta
  de outro transporte preservada; e uma falha que não interrompe os outros eventos.
  O `TransportCallback` e o `CoreParametersHelper` são mocks.

Não há teste de integração com banco. O caminho até o `lead_donotcontact` foi
validado à mão no Docker (Mautic 5.2.9, MariaDB), com o curl da seção Instalação.

## Desenvolvimento

Os testes e a análise estática rodam dentro de uma instalação do Mautic 5. O
`phpunit.xml` e o `phpstan.neon` usam o autoload em `/var/www/html/vendor`, que é o
caminho na imagem Docker oficial `mautic/mautic`. Versões usadas: PHPUnit 10.5 e
PHPStan 1.12.

```bash
cd /var/www/html/docroot/plugins/SendgridCallbackBundle

# Testes
phpunit

# Análise estática (nível 8)
phpstan analyse -c phpstan.neon
```

Estilo de código: o `ecs.php` da raiz do Mautic
(`ecs check plugins/SendgridCallbackBundle --config ecs.php`).
