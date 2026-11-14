# Changelog

Um bloco por dia, do mais novo para o mais antigo. Formato fixo: `## AAAA-MM-DD` e uma linha `- ` por mudança.

## 2026-11-14
- Primeira versão (1.0.0). O /mailer/callback do Mautic 5 passa a tratar os eventos do SendGrid Event Webhook enviados pelo n8n, com o envio por SMTP mesmo. Antes, ele respondia 404 "No email transport that could process this callback was found"
- bounce (menos blocked) e dropped por Bounced Address ou Invalid marcam o contato como Não Contatar, motivo Bounced
- spamreport, unsubscribe, group_unsubscribe e dropped por descadastro ou spam viram Não Contatar, motivo Unsubscribed, só com sendgrid_callback_process_unsubscribes ligado. O padrão é desligado porque, quando os eventos de vários clientes chegam a todas as instâncias, um descadastro num cliente marcaria Não Contatar nos outros
- O callback exige o token sendgrid_callback_token no header X-Mautic-Callback-Token (ou ?token=). Sem token configurado, o plugin recusa tudo com 503
