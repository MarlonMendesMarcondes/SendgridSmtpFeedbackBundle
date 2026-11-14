<?php

declare(strict_types=1);

return [
    'name'        => 'SendGrid Callback',
    'description' => 'Recebe no /mailer/callback os eventos do SendGrid (bounce, dropped, spam, descadastro) e marca o contato como Não Contatar. Funciona com envio por SMTP.',
    'version'     => '1.0.0',
    'author'      => 'Educa Insights',

    'parameters' => [
        // Token que o n8n envia no header X-Mautic-Callback-Token (ou ?token=).
        // Vazio = o plugin recusa todo callback do SendGrid (responde 503).
        'sendgrid_callback_token' => '',

        // false: só bounce e dropped por endereço inválido viram Não Contatar.
        // true: spamreport, unsubscribe, group_unsubscribe e dropped por
        // descadastro/spam também viram Não Contatar (motivo: descadastrado).
        // Deixe false se os eventos de vários clientes chegam a todas as
        // instâncias (ver README).
        'sendgrid_callback_process_unsubscribes' => false,
    ],
];
