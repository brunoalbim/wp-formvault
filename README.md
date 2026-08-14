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

O webhook envia o ID do formulário em `source` e os campos do contato em `form`. Os nomes configurados no mapeamento são preservados, inclusive espaços, acentos e letras maiúsculas. A chave `form.utm` é reservada e contém somente os dados da última campanha atribuída ao contato.

```json
{
	"source": "example_contact",
	"form": {
		"Nome": "Maria",
		"E-mail": "maria@empresa.com",
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
