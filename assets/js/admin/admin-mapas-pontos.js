/**
 * EXTRACT HUNTERS · PAINEL — CRIAR PONTOS NO MAPA (/admin/mapas/pontos).
 *
 * Faz uma coisa só: deixar o formulário mostrar o que vai acontecer ANTES de salvar.
 *   - troca de origem do ícone esconde os dois campos que não valem mais;
 *   - o desenho ao lado é redesenhado a cada tecla, incluindo a cor;
 *   - o nome digitado aparece sob o desenho, como apareceria na paleta.
 *
 * MELHORIA PROGRESSIVA DE VERDADE: sem este arquivo os três campos de ícone ficam TODOS
 * visíveis, cada um com seu `name`, e o servidor lê o que corresponde à origem escolhida no
 * <select> (MapPointsController::formDoPost). Nada aqui é requisito — o que ele faz é tirar
 * da tela o que não se aplica.
 *
 * A tabela de vetores chega num <script type="application/json"> escrito só na página do
 * FORMULÁRIO — nunca na listagem, que não troca ícone nenhum. É ~8 KB numa tela só, em troca
 * de nenhuma rota nova, nenhuma requisição e um preview que funciona mesmo se a rede cair no
 * meio da edição.
 */

const doc = document;
const form = doc.querySelector('[data-mpt-form]');

if (form) {
  const selOrigem = form.querySelector('[data-mpt-origem]');
  const hintOrig  = form.querySelector('[data-mpt-origem-hint]');
  const selCor    = form.querySelector('[data-mpt-cor]');
  const campoNome = form.querySelector('[data-mpt-label]');
  const preview   = form.querySelector('[data-mpt-preview]');
  const prevNome  = form.querySelector('[data-mpt-preview-name]');
  const campos    = [...form.querySelectorAll('[data-mpt-campo]')];

  /* As dicas de cada origem já estão no DOM (o servidor escreveu a da origem atual); as
     outras vêm daqui para não duplicar o texto em dois lugares. */
  const HINTS = {
    glyph:  'Os desenhos que a tela do mapa já traz (extração, base, ninho…). Escalam melhor em marcador pequeno.',
    symbol: 'Nome do ícone na fonte que a HUD já carrega — ex.: local_cafe, local_mall, local_gas_station.',
    image:  'Arte própria: SVG, PNG ou WebP quadrado. Um SVG enviado leva a própria cor.',
  };

  /** Campo de valor da origem ativa (é dele que sai o que o preview desenha). */
  const fonteDe = (origem) => {
    const caixa = campos.find((c) => c.dataset.mptCampo === origem);
    if (!caixa) return null;
    return caixa.matches('input, select') ? caixa : caixa.querySelector('input, select');
  };

  function mostrarCampos() {
    const origem = selOrigem?.value || 'symbol';
    campos.forEach((c) => { c.hidden = c.dataset.mptCampo !== origem; });
    if (hintOrig) hintOrig.textContent = HINTS[origem] ?? '';
  }

  function pintar() {
    if (!preview) return;
    const cor = selCor?.value || 'white';
    const alvo = preview.querySelector('.mpt-ic');
    if (alvo) alvo.className = `mpt-ic mpt-ic--lg mpt-ic--${cor}`;
  }

  /* ---- desenho do ícone --------------------------------------------------- */
  let glifos = {};
  try {
    glifos = JSON.parse(doc.querySelector('[data-mpt-glifos]')?.textContent || '{}');
  } catch { glifos = {}; }

  function desenhar() {
    if (!preview) return;
    const origem = selOrigem?.value || 'symbol';
    const valor = (fonteDe(origem)?.value || '').trim();
    const caixa = preview.querySelector('.mpt-ic');
    if (!caixa) return;

    if (origem === 'image') {
      // Caminho ainda em branco mostra a moldura vazia, e não uma imagem quebrada.
      caixa.innerHTML = valor
        ? `<img src="${form.dataset.mptMedia}${encodeURI(valor)}" alt="" decoding="async">`
        : '';
      return;
    }
    if (origem === 'symbol') {
      caixa.innerHTML = `<span class="ms" aria-hidden="true">${valor.replace(/[^a-z0-9_]/g, '')}</span>`;
      return;
    }
    caixa.innerHTML = glifos[valor]
      ? `<svg viewBox="0 0 24 24" aria-hidden="true" fill="currentColor">${glifos[valor]}</svg>`
      : '<span class="ms" aria-hidden="true">help</span>';
  }

  const atualizar = () => { mostrarCampos(); pintar(); desenhar(); };

  selOrigem?.addEventListener('change', atualizar);
  selCor?.addEventListener('change', pintar);
  campos.forEach((c) => {
    c.addEventListener('input', desenhar);
    c.addEventListener('change', desenhar);
  });
  campoNome?.addEventListener('input', () => {
    if (prevNome) prevNome.textContent = campoNome.value.trim() || 'SEM NOME';
  });

  mostrarCampos();
}
