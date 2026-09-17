# WP FormVault

Plugin WordPress para registrar, validar e armazenar contatos enviados por formulários criados em código. E-mail e webhook são integrações principais; Pipedrive e Brevo são opcionais.

## Gerar o ZIP para instalação

Após fazer o commit das alterações, execute `./package.sh` na raiz do repositório. O script cria `wp-formvault-VERSÃO.zip` com a pasta interna `wp-formvault/`, usando apenas os arquivos do commit atual. O ZIP não inclui `.git`, o script de empacotamento nem os arquivos de configuração do Git.

## Implementação de um formulário

### 1. Registrar o esquema

Use um identificador único e declare todos os campos que poderão ser recebidos. O atributo `name` de cada controle HTML deve ser igual à chave registrada.

```php
add_filter( 'wpfv_registered_forms', function ( $forms ) {
	$forms['example_contact'] = array(
		'name'              => 'Formulário de contato',
		'description'       => 'Contato geral do site.',
		'redirect_fragment' => 'contact',
		'fields'            => array(
			'name'    => array( 'label' => 'Nome', 'type' => 'text', 'required' => true ),
			'email'   => array( 'label' => 'E-mail', 'type' => 'email', 'required' => true ),
			'phone'   => array( 'label' => 'Telefone', 'type' => 'tel', 'required' => false ),
			'subject' => array(
				'label'    => 'Assunto',
				'type'     => 'select',
				'required' => true,
				'options'  => array( 'Comercial', 'Suporte', 'Outro' ),
			),
			'message' => array( 'label' => 'Mensagem', 'type' => 'textarea', 'required' => true ),
		),
	);

	return $forms;
} );
```

Tipos aceitos: `text`, `textarea`, `email`, `tel`, `url`, `integer`, `checkbox`, `select` e `hidden`. Em `select`, `options` é uma lista dos valores permitidos; o `value` enviado pelo HTML deve coincidir exatamente com um item dessa lista.

### 2. Criar a marcação

```php
<form
	action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
	method="post"
	data-wpfv-form="example_contact"
>
	<?php wpfv_form_hidden_fields( 'example_contact' ); ?>

	<input type="text" name="name" required>
	<input type="email" name="email" required>
	<input type="tel" name="phone">
	<select name="subject" required>
		<option value="">Selecione</option>
		<option value="Comercial">Comercial</option>
		<option value="Suporte">Suporte</option>
		<option value="Outro">Outro</option>
	</select>
	<textarea name="message" required></textarea>
	<button type="submit">Enviar</button>
</form>
```

O helper adiciona `action`, ID do formulário, nonce, marcador temporal assinado e honeypot. O atributo `data-wpfv-form` habilita envio AJAX, avaliação do reCAPTCHA quando configurado e captura automática de página, UTMs, `gclid` e `fbclid`; sem JavaScript, o envio continua funcionando por `admin-post.php`, mas um formulário protegido pelo reCAPTCHA sem token será colocado em quarentena.

### 3. Configurar e testar

1. Ative o plugin e acesse **Contatos > Formulários**.
2. Configure armazenamento, notificação, webhook e validações.
3. Integrações opcionais só aparecem após cadastrar suas credenciais em **Contatos > Configurações**.
4. Faça um envio e confirme o registro em **Contatos > Envios**.

Somente campos declarados no esquema são processados. Não inclua credenciais, URLs ou dados específicos de cliente no código reutilizável.

## Proteção antispam

As proteções locais são automáticas: honeypot, tempo mínimo de preenchimento, limite de cinco envios por IP a cada dez minutos e supressão de payloads idênticos por dez minutos. Para o rate limit, o plugin usa somente um identificador criptográfico do IP. O IP bruto não é persistido pelo plugin; quando o reCAPTCHA está ativo, ele é enviado ao Google como contexto da avaliação, o que deve ser refletido na política de privacidade do site.

Para habilitar o Google Cloud reCAPTCHA baseado em pontuação:

1. No projeto Google Cloud pertencente ao cliente, crie uma chave reCAPTCHA para website baseada em pontuação e autorize o hostname do site.
2. Crie uma API key restrita à API reCAPTCHA Enterprise.
3. Em **Contatos > Configurações > Antispam**, informe Project ID, site key e API key.
4. Em **Contatos > Formulários**, abra a aba **Antispam**, habilite o reCAPTCHA e comece com score mínimo `0.5`.

Em **Configurações > Antispam**, o botão **Testar conexão** consulta a API com as credenciais já salvas e um token deliberadamente inválido. Um retorno de token inválido confirma o acesso do servidor à API; o teste não valida o JavaScript, o domínio nem o score de um envio real. Cada clique faz uma avaliação no Google e pode contar na cota. O campo **API key** exige uma chave de API do Google Cloud vinculada ao Project ID, não a chave secreta legada exibida para integrações de terceiros.

Envios abaixo do score, com token inválido ou com erro permanente de configuração são armazenados com status **Spam** e não acionam e-mail, webhook ou CRM. O visitante recebe a mesma confirmação neutra de um envio legítimo. Um administrador pode revisar o registro e usar **Aprovar como legítimo** para liberar as integrações. O spam é excluído automaticamente após 90 dias.

Falhas transitórias de rede, HTTP 429 ou erros 5xx do Google usam somente as proteções locais e aparecem como estado degradado no painel. Erros de credencial colocam o envio em quarentena para não desabilitar a proteção silenciosamente. Valide primeiro em homologação e monitore a distribuição dos scores por 7–14 dias antes de tornar o limiar mais rigoroso.

Quando a API do Google devolver HTTP 400, 401 ou 403, o diagnóstico com status HTTP, códigos de erro e mensagem curta sanitizada aparecerá em **Contatos > Configurações > Antispam**, no detalhe dos novos envios em quarentena e nos logs. O plugin omite as chaves e o token da mensagem antes de armazená-la.

Se o site usa Content Security Policy, adicione `https://www.google.com/recaptcha/` e `https://www.gstatic.com/recaptcha/` a `script-src`, `https://www.google.com/recaptcha/` e `https://recaptcha.google.com/recaptcha/` a `frame-src`, e `https://www.google.com/recaptcha/` a `connect-src`. Revise também a política de privacidade do site antes da ativação.

## Payload do webhook

O webhook envia o ID do formulário tanto em `source` quanto em `form.source`. Os campos do contato ficam isolados em `form.fields`, preservando os nomes configurados no mapeamento, inclusive espaços, acentos e letras maiúsculas. `form.page` identifica a página em que o envio aconteceu e `form.utm` contém somente os dados da última campanha atribuída ao contato.

```json
{
	"source": "example_contact",
	"form": {
		"source": "example_contact",
		"fields": {
			"Nome": "Maria",
			"E-mail": "maria@empresa.com"
		},
		"page": {
			"title": "Contato",
			"conversion_url": "https://example.com/contato/"
		},
		"utm": {
			"utm_source": "google",
			"utm_medium": "cpc",
			"utm_campaign": "campanha-exemplo"
		}
	}
}
```

## Extensão

Novas integrações devem implementar `WPFV_Integration` e ser registradas pelo filtro `wpfv_integrations`. O hook `wpfv_submission_stored` só é disparado depois que o envio passa pelas proteções antispam ou é aprovado manualmente.

## RD Station CRM v2

1. Crie um aplicativo no App Publisher da RD Station e cadastre como redirect URI a URL exibida em **Contatos > Configurações > Integrações > RD CRM**.
2. Salve o Client ID e Client Secret no plugin e clique em **Conectar** para concluir o OAuth2. O secret e os tokens salvos não voltam a ser exibidos.
3. Em **Contatos > Formulários**, abra a aba **RD CRM**, escolha funil, etapa e responsável, defina o título e mapeie obrigatoriamente nome e e-mail.
4. Use `name`, `email`, `phone` e `job_title` nos campos padrão de contato. No mapeamento **Empresa**, selecione **Nome da empresa** e, se desejar, descrição, site, endereço e campos personalizados retornados pelo RD CRM.

A empresa só é consultada quando o campo **Nome da empresa** está mapeado e preenchido no envio. O plugin procura uma empresa com o mesmo nome exato, atualiza seus outros campos mapeados ou cria uma nova empresa e associa seu ID ao contato e à negociação. Ao criar uma empresa, envia como responsável o usuário selecionado para a negociação; uma empresa já existente mantém seu responsável. Sem nome de empresa, o fluxo continua sem consultar nem cadastrar empresas.

Se a conta do RD CRM exigir campos personalizados, mapeie também esses campos e garanta que o formulário envie valores válidos. Os seletores de Contato, Empresa e Negociação consultam quais campos são obrigatórios. Em erros HTTP 422 de campo obrigatório de empresa, o plugin consulta a lista de campos para mostrar o nome e o slug; se o ID do erro não aparecer no catálogo, a mensagem lista os campos conhecidos pela conexão e orienta a conferir a conta vinculada ao OAuth. Campos de seleção múltipla são enviados como listas de valores.

Quando não houver um campo correspondente no formulário, escolha **Valor fixo** em **Campo do formulário ou valor fixo** e informe o conteúdo a enviar. Para um campo personalizado de seleção múltipla, escolha **Lista com um valor**; o plugin sugere esse formato quando o catálogo identifica o tipo. O valor precisa ser uma opção aceita pelo RD CRM. Mapeamentos existentes que usam campos do formulário continuam funcionando como antes.

Se **Testar conexão** ou os catálogos falharem, o painel mostra a operação, o status HTTP e o detalhe retornado pelo RD CRM. Consulte **Contatos > Logs** para o registro da falha (com o registro de eventos habilitado). Um `refresh_token` expirado ou revogado exige reconectar a integração em **Contatos > Configurações > Integrações**. As seleções de funil, etapa, responsável e mapeamentos já salvos são preservadas enquanto o catálogo estiver indisponível.

Falhas HTTP 429 ao consultar catálogos são temporárias: aparecem junto ao formulário e nos logs, sem manter um aviso de erro por 24 horas no topo de Configurações. Outros avisos salvos nessa página podem ser removidos por **Dispensar aviso**. As linhas de mapeamento que usam a mesma entidade compartilham uma única consulta de campos enquanto ela estiver em andamento, para reduzir chamadas repetidas à API.

Funis, etapas, responsáveis e campos do RD CRM consultados no painel ficam em transients do WordPress por 10 minutos. Sem cache de objetos persistente, os transients ficam no banco de dados do site; com cache de objetos persistente, ficam nesse cache. O botão **Atualizar dados do RD CRM** na aba RD CRM do formulário descarta os catálogos em cache e recarrega a página. Salve alterações pendentes antes de usá-lo. Reconectar, desconectar ou alterar as credenciais do RD CRM também invalida os catálogos. Falhas de consulta não são armazenadas em cache.

O plugin agenda uma verificação diária pelo WP-Cron para renovar o token mesmo sem novos envios. O WP-Cron executa tarefas quando há visitas ao site; se estiver desativado ou o site passar muitos dias sem visitas, configure uma chamada ao `wp-cron.php` pelo agendador do servidor. Depois de reconectar uma integração com token inválido, use **Testar conexão** para confirmar o acesso.

O access token é renovado automaticamente antes da expiração e uma resposta HTTP 401 provoca um único refresh e reenvio. Em cada envio, o plugin busca o contato pelo e-mail exato, atualiza ou cria o contato e abre uma negociação `ongoing` associada. Falhas ficam disponíveis no histórico de entregas para tentativa manual; como a API não fornece idempotência adequada a esse fluxo, uma resposta perdida após uma criação remota pode excepcionalmente causar duplicidade.
