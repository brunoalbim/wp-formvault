# WP Attribution Tracker

Plugin WordPress leve e independente para atribuição de origem em formulários do Elementor Pro. Não cria tabelas, painel, formulários ou webhooks próprios.

## O que ele faz

- Classifica a visita com precedência `UTM > click ID > referenciador externo > direto`.
- Mantém a primeira atribuição e a última atribuição não direta por 90 dias.
- Preserva a fonte original e gera `normalized_source`.
- Adiciona os dados ao registro do Elementor antes das ações de email, webhook e Submissions.
- Funciona com formulários e popups inseridos dinamicamente.
- Reaproveita um cookie legado `wpfv_attribution` quando ele existe, mas grava seu próprio cookie `wpat_attribution`.

## Instalação

Instale o ZIP pelo painel do WordPress e ative o plugin. Não há tela de configuração.

O adaptador adiciona automaticamente os campos ao registro do Elementor. Para máxima compatibilidade com extensões de terceiros, também é possível criar campos `Hidden` no formulário usando IDs como `utm_source`, `normalized_source`, `utm_medium`, `utm_channel`, `utm_campaign`, `attribution_method`, `landing_url` e `landing_referrer`; o tracker preencherá esses campos antes do envio.

## Campos enviados

Além dos campos sem prefixo, que representam a última origem não direta, o plugin adiciona versões `first_*` e `last_*`, click IDs conhecidos, URL e referenciador da primeira entrada e URL/título da página de envio.

## Aliases personalizados

O filtro `wpat_attribution_source_aliases` recebe um array por fonte canônica contendo `family` e `aliases`. Famílias permitidas: `search`, `social`, `email` e `referral`.

```php
add_filter(
	'wpat_attribution_source_aliases',
	function ( $sources ) {
		$sources['meu_parceiro'] = array(
			'family'  => 'referral',
			'aliases' => array( 'parceiro', 'portal_parceiro' ),
		);
		return $sources;
	}
);
```

Aliases usam igualdade ou prefixo delimitado. Não há busca livre por trecho nem aproximação ortográfica.
