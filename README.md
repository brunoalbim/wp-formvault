# WP FormVault

Plugin WordPress para registrar, validar e armazenar contatos enviados por formulários criados em código. E-mail e webhook são integrações principais; Pipedrive e Brevo são opcionais.

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

	<input type="text" name="wpfv_website" tabindex="-1" autocomplete="off" aria-hidden="true">
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

O helper adiciona `action`, ID do formulário e nonce. O atributo `data-wpfv-form` habilita envio AJAX e captura automática de página, UTMs, `gclid` e `fbclid`; sem JavaScript, o envio continua funcionando por `admin-post.php`.

### 3. Configurar e testar

1. Ative o plugin e acesse **Contatos > Formulários**.
2. Configure armazenamento, notificação, webhook e validações.
3. Integrações opcionais só aparecem após cadastrar suas credenciais em **Contatos > Configurações**.
4. Faça um envio e confirme o registro em **Contatos > Envios**.

Somente campos declarados no esquema são processados. Não inclua credenciais, URLs ou dados específicos de cliente no código reutilizável.

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

Novas integrações devem implementar `WPFV_Integration` e ser registradas pelo filtro `wpfv_integrations`.

## RD Station CRM v2

1. Crie um aplicativo no App Publisher da RD Station e cadastre como redirect URI a URL exibida em **Contatos > Configurações > Integrações > RD CRM**.
2. Salve o Client ID e Client Secret no plugin e clique em **Conectar** para concluir o OAuth2. O secret e os tokens salvos não voltam a ser exibidos.
3. Em **Contatos > Formulários**, abra a aba **RD CRM**, escolha funil, etapa e responsável, defina o título e mapeie obrigatoriamente nome e e-mail.
4. Use `name`, `email`, `phone` e `job_title` nos campos padrão de contato. Para campos personalizados de contato ou negociação, use `custom:ID_DO_CAMPO`.

O access token é renovado automaticamente antes da expiração e uma resposta HTTP 401 provoca um único refresh e reenvio. Em cada envio, o plugin busca o contato pelo e-mail exato, atualiza ou cria o contato e abre uma negociação `ongoing` associada. Falhas ficam disponíveis no histórico de entregas para tentativa manual; como a API não fornece idempotência adequada a esse fluxo, uma resposta perdida após uma criação remota pode excepcionalmente causar duplicidade.
