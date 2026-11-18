# Instruções para agentes

Plugin WordPress que registra, valida, armazena e entrega formulários criados
em código. O `README.md` é a documentação de uso; este arquivo resume o que um
agente precisa saber antes de alterar o plugin ou integrar um tema a ele.

## Estrutura

- `wp-formvault.php`: cabeçalho, constantes, `require_once` e o helper
  `wpfv_form_hidden_fields()`.
- `includes/`: uma classe por arquivo (`class-*.php`), instanciadas em
  `includes/class-plugin.php`.
- `public/js/attribution.js`: envio AJAX, atribuição, reCAPTCHA, validação de
  telefone/e-mail, anexos e renovação de tokens de todos os formulários com
  `data-wpfv-form`.
- `admin/`: painel (**Contatos**).
- `tests/`: testes Node do classificador de atribuição.

## Integrar um formulário (tema ou outro plugin)

1. Registre o esquema pelo filtro `wpfv_registered_forms`. O `name` de cada
   controle HTML deve ser igual à chave do campo; em `select`, o `value` enviado
   deve coincidir exatamente com um item de `options`.
2. Na marcação, use `data-wpfv-form="<id>"` e chame
   `wpfv_form_hidden_fields( '<id>' )` dentro do `<form>`.
3. Não substitua o envio do plugin por um `fetch` próprio. Para reagir ao
   resultado, escute o evento `wpfv:success` no formulário. O plugin chama
   `form.reset()` antes desse evento; capture os valores no `submit` (fase de
   captura) se precisar deles depois.
4. O botão de envio tem o `textContent` trocado por "Enviando…" e restaurado:
   use somente texto nele, sem ícones ou elementos filhos.
5. Não use `novalidate` sem reproduzir a validação do navegador; sem ela, um
   envio vazio chega ao servidor.

## Cache de página: problema conhecido e correção

**Sintoma:** com todos os campos preenchidos, o formulário mostra "Revise os
campos obrigatórios e tente novamente." e a resposta de `admin-ajax.php` é
HTTP 422 com `"fields":[]`. Ou, pior: o visitante vê sucesso, mas nenhum envio
aparece em **Contatos > Envios**.

**Causa:** o HTML guardado por um cache de página traz um `wpfv_nonce` de outro
usuário (cópia anônima entregue a quem está logado) ou vencido (12–24 h), e um
`wpfv_started_at` que pode ter mais de 24 h (`MAX_FORM_AGE`). O nonce inválido
gera a recusa; o marcador vencido faz `WPFV_Antispam::early_check()` descartar o
envio silenciosamente.

**Correção (1.11.0):** `includes/class-form-tokens.php` expõe
`admin-ajax.php?action=wpfv_form_tokens`, sem cache, e `attribution.js` o chama
na primeira interação e antes de cada envio. As regras do marcador temporal
estão documentadas na classe e no README ("Cache de página e tokens de
segurança").

Ao alterar o plugin:

- Preserve essa renovação em qualquer novo fluxo de envio; não volte a confiar
  somente nos valores impressos no HTML.
- Não deixe a renovação contornar o tempo mínimo de preenchimento para
  marcadores ausentes ou forjados.
- Ao diagnosticar "Revise os campos" com `fields` vazio, verifique primeiro
  nonce e cache antes de procurar erro nos campos.

Ao integrar um tema: não crie endpoints próprios para renovar tokens. Se
precisar suportar versões anteriores à 1.11.0, condicione o contorno a
`! class_exists( 'WPFV_Form_Tokens' )`.

## Proteções que descartam envios em silêncio

Para não confundir com perda de dados ao testar: honeypot preenchido, envio
em menos de 2 s, marcador temporal inválido ou vencido, mais de 5 envios por
IP em 10 minutos e payload idêntico em 10 minutos respondem "sucesso" sem
gravar (`submission_id` igual a `0`). Use dados diferentes a cada teste.

## Comandos

- PHP: `find . -name '*.php' -not -path './packages/*' -print0 | xargs -0 -n1 php -l`
- JavaScript: `node --check public/js/attribution.js && node --test tests/attribution.test.js`
- Pacote: `./package.sh` (depois do commit; usa `git archive`).

## Versão

Ao alterar o comportamento, incremente `Version:` e `WPFV_VERSION` em
`wp-formvault.php` juntos. O workflow `.github/workflows/release.yml` publica o
release ao detectar a nova versão na `main`.
