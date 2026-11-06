# ManiAgenda

Sistema de agendamento online para manicure (PHP + MySQL).

## Notificações por SMS (RF04)

O sistema envia, para a cliente: **confirmação** (logo após agendar ou remarcar), **lembrete 24h** (pede resposta "SIM") e **lembrete 2h**; e para a manicure, um **aviso 2h antes** de cada atendimento. Tudo por SMS.

**Como funciona:** cada mensagem vira uma linha na tabela `notificacoes` (fila + histórico). Erros temporários são repetidos (2 min, 10 min, 30 min) e depois marcados como "falhou" (reenvio manual em **Admin → Notificações**). O webhook atualiza o status (enviado → entregue / falhou) e trata a resposta "SIM" da cliente (o agendamento vira *confirmado*). Mensagens de um agendamento que foi cancelado ou remarcado antes do envio são descartadas.

### Ativar o envio real

1. **Banco:** rode `config/migracao_notificacoes.sql` (bancos existentes; instalações novas já usam o `database.sql`). Requer também `config/migracao_lembretes.sql`, caso ainda não tenha rodado. Se você já havia criado a tabela `notificacoes` na versão com WhatsApp, rode `config/migracao_notificacoes_somente_sms.sql` em vez disso.
2. **Credenciais:** crie `config/integracoes.local.php` (fica fora do Git) só com o que mudar em relação a `config/integracoes.php`, por exemplo:

   ```php
   <?php
   return [
       'url_base'          => 'https://seusite.com.br/app.agenda',
       'manicure_telefone' => '(45) 99999-8888',
       'notificacoes'      => ['webhook_token' => 'GERE_UM_VALOR_LONGO'],
       'sms' => ['provedor' => 'twilio', 'twilio' => ['account_sid' => '...', 'auth_token' => '...', 'from' => '+1...']],
   ];
   ```

   Provedores: `twilio` ou `zenvia`. Com `'simulado'` (padrão) nada é enviado, só registrado.
3. **Teste:** em **Admin → Notificações → Enviar mensagem de teste**, informe seu celular.
4. **Cron** (a cada 5 min), que cria os lembretes e reenvia o que falhou:
   `*/5 * * * * php /caminho/app.agenda/scripts/enviar_lembretes.php`
   (Windows/XAMPP: Agendador de Tarefas com `php.exe` + o caminho do script.)
5. **Webhook** (status de entrega e respostas "SIM"; não é necessário para os lembretes saírem): aponte o provedor para
   `https://seusite.com.br/app.agenda/webhooks/notificacoes.php?token=SEU_TOKEN`
   Twilio: coloque a URL em `status_callback` e no webhook de mensagem recebida do número. Com o `auth_token` configurado, a assinatura `X-Twilio-Signature` é obrigatória, então a `url_base` precisa ser exatamente o endereço público que a Twilio chama.

### Observações

- Os textos são curtos e sem acentos no envio, para caber em poucos segmentos de SMS (160 caracteres cada).
- Os formatos de cada provedor seguem a documentação pública, mas foram testados apenas contra um servidor simulado local, nunca contra contas reais: faça o envio de teste antes de usar.
- Logs: `scripts/logs/lembretes.log`.
