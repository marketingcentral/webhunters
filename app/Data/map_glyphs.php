<?php declare(strict_types=1);

/**
 * VETORES DOS MARCADORES DO MAPA — o repertório de desenho que o jogo traz de nascença.
 *
 * Cada valor é o CONTEÚDO de um <svg viewBox="0 0 24 24" fill="currentColor">, sem a tag: a
 * cor vem de fora, então o mesmo desenho serve a extração verde e a ninho vermelho.
 *
 * POR QUE ISTO SAIU DE DENTRO DA VIEW DO MAPA
 * O painel precisa desenhar os MESMOS ícones — na paleta do editor, na ficha do catálogo de
 * pontos e na pré-visualização do formulário. Enquanto a tabela morava dentro de
 * app/Views/pages/mapa.php, a única saída do painel era redesenhar tudo de novo, e duas
 * cópias de um desenho divergem no primeiro ajuste. Agora há uma tabela só, lida pelos dois
 * lados com data_mock('map_glyphs').
 *
 * Conteúdo confiável (definido aqui, não vem de requisição): as views imprimem sem escapar.
 */
return [
    // Objetivo de missão: alvo com marca — o ponto que a missão manda alcançar
    'mission'    => '<circle cx="12" cy="12" r="3"/><path fill="none" stroke="currentColor" stroke-width="1.7" d="M12 3.4a8.6 8.6 0 1 0 0 17.2 8.6 8.6 0 0 0 0-17.2Z"/><path fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" d="M12 1.2v3.4M12 19.4v3.4M1.2 12h3.4M19.4 12h3.4"/>',
    'extraction' => '<path d="M8.6 4.6h10.8v10.8h-2.9V9.5L6.9 19.2 4.8 17.1 14.5 7.4H8.6z"/><path d="m3.4 12.4 2.4 2.4-2.2 2.2-2.4-2.4z" opacity=".85"/>',
    'base'       => '<path fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" d="M12 2.6 4.8 5.5v5.9c0 4.3 3 8.3 7.2 9.4 4.2-1.1 7.2-5.1 7.2-9.4V5.5z"/><path d="m12 7.4 1.5 3.1 3.4.5-2.5 2.4.6 3.4-3-1.6-3 1.6.6-3.4-2.5-2.4 3.4-.5z"/>',
    'camp'       => '<path d="M12 1.4c.4 2.5-.9 3.7-2.2 5.1C8.4 8 7.6 9.5 7.6 11.2a4.4 4.4 0 0 0 8.8 0c0-1.6-.5-3-1.6-4.2-.4 1-1 1.5-1.7 1.8.4-2.7-.1-5.2-1.1-7.4Z"/><path fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" d="M4.8 17.4 19.2 21.6M19.2 17.4 4.8 21.6"/>',
    'loot'       => '<path fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" d="M12 2.4 20.8 7v10L12 21.6 3.2 17V7z"/><path fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" d="M3.6 7.2 12 11.7l8.4-4.5M12 11.9v9.4"/><path d="M8.2 13.1v3.2h1.9v-3.2zM13.9 13.1v3.2h1.9v-3.2z"/>',
    'event'      => '<path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" d="M12 2.6 21.4 12 12 21.4 2.6 12z"/><path d="M12 7.4 16.6 12 12 16.6 7.4 12z"/>',
    'risk'       => '<rect x="3" y="5" width="18" height="14" fill="none" stroke="currentColor" stroke-width="1.5"/><g stroke="currentColor" stroke-width="1.7" fill="none"><path d="M3 10 8 5M3 15 13 5M4 19 18 5M9 19 21 7M14 19 21 12M19 19 21 17"/></g>',
    'enemy'      => '<path d="M12 2.2c-4.3 0-7.5 3.1-7.5 7.3 0 2.3 1 4.2 2.7 5.5v2.6c0 .6.5 1.1 1.1 1.1h7.4c.6 0 1.1-.5 1.1-1.1v-2.6c1.7-1.3 2.7-3.2 2.7-5.5 0-4.2-3.2-7.3-7.5-7.3ZM8.9 8.4a1.9 1.9 0 1 1 0 3.8 1.9 1.9 0 0 1 0-3.8Zm6.2 0a1.9 1.9 0 1 1 0 3.8 1.9 1.9 0 0 1 0-3.8ZM12 13.4l1.1 2.1h-2.2z"/><path d="M8.6 19.1h1.7v2.7H8.6zM11.2 19.1h1.6v2.7h-1.6zM13.7 19.1h1.7v2.7h-1.7z"/>',
    'radio'      => '<path d="M9.9 8.5h4.2l3.9 12.9h-2.5l-.8-2.9h-5.4l-.8 2.9H6zM10.8 15.9h2.4L12 11.6z"/><path d="M5.6 2.4 7 3.8a5.9 5.9 0 0 0 0 4.4L5.6 9.6a7.8 7.8 0 0 1 0-7.2ZM18.4 2.4a7.8 7.8 0 0 1 0 7.2L17 8.2a5.9 5.9 0 0 0 0-4.4z" opacity=".9"/><path d="M8.6 4.6 9.9 5.9a2.6 2.6 0 0 0 0 .4L8.6 7.6a4 4 0 0 1 0-3ZM15.4 4.6a4 4 0 0 1 0 3L14.1 6.3a2.6 2.6 0 0 0 0-.4z" opacity=".9"/>',
    'village'    => '<path d="M10.4 2.6h3.2v2.6h3.1v16.2h-3.9v-4.2h-1.6v4.2H6.5V5.2h3.9zM8.6 7.6v1.9h1.9V7.6zm4.9 0v1.9h1.9V7.6zM8.6 11.4v1.9h1.9v-1.9zm4.9 0v1.9h1.9v-1.9z"/><path d="M2.6 9.9h3.1v11.5H2.6zM18.3 9.9h3.1v11.5h-3.1z"/>',
    'enemy-legend' => '<path fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" d="M8.4 2.4h7.2l4.6 4.7v9.8L15.6 21.6H8.4L3.8 16.9V7.1z"/><path d="M12 6.2a4.6 4.6 0 0 0-4.6 4.5c0 1.4.6 2.6 1.6 3.4v1.5c0 .4.3.7.7.7h4.6c.4 0 .7-.3.7-.7v-1.5c1-.8 1.6-2 1.6-3.4A4.6 4.6 0 0 0 12 6.2Zm-1.9 3.8a1.2 1.2 0 1 1 0 2.3 1.2 1.2 0 0 1 0-2.3Zm3.8 0a1.2 1.2 0 1 1 0 2.3 1.2 1.2 0 0 1 0-2.3Z"/>',
    'locked'     => '<path d="M12 2.2A4.6 4.6 0 0 0 7.4 6.8v2.1H6.2c-.7 0-1.2.5-1.2 1.2v10.5c0 .7.5 1.2 1.2 1.2h11.6c.7 0 1.2-.5 1.2-1.2V10.1c0-.7-.5-1.2-1.2-1.2h-1.2V6.8A4.6 4.6 0 0 0 12 2.2Zm0 2.2a2.4 2.4 0 0 1 2.4 2.4v2.1H9.6V6.8A2.4 2.4 0 0 1 12 4.4Z"/>',
    /* PRD v3 §23 — mundo persistente */
    // Base Hunter (governamental): escudo com chevrons de patente
    'gov-base'   => '<path fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" d="M12 2.6 4.8 5.5v5.9c0 4.3 3 8.3 7.2 9.4 4.2-1.1 7.2-5.1 7.2-9.4V5.5z"/><path fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" d="m8.6 10.2 3.4-2.6 3.4 2.6M8.6 14.2l3.4-2.6 3.4 2.6"/>',
    // Instalação Aegis: bastião/blindagem
    'aegis'      => '<path fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" d="M3.4 20.6V9.2l3.2-2.4V4.2h3.2v2.6h1.2V4.2h3.2v2.6h1.2V4.2h3.2v2.6l-.6 2.4v11.4z"/><path d="M9.6 13.2h4.8v7.4H9.6z"/>',
    // Acampamento Frontier: tenda + trilha
    'frontier'   => '<path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" d="M12 3.4 2.8 19.6h18.4z"/><path d="M12 9.2 8.4 19.6h7.2z"/><path fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-dasharray="1.6 1.8" d="M3 22h18"/>',
    // Base de campo: casa simples com antena
    'field-base' => '<path fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" d="M4 12.4 12 5.6l8 6.8V20.4H4z"/><path d="M10 14h4v6.4h-4z"/><path fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" d="M16.5 3v5.2M15 4.4h3"/>',
    // Ninho / colônia Veyra: núcleo com filamentos
    'nest'       => '<circle cx="12" cy="12" r="3.2"/><path fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" d="M12 8.8V3.2M12 15.2v5.6M8.8 12H3.2M15.2 12h5.6M9.7 9.7 5.8 5.8M14.3 14.3l3.9 3.9M14.3 9.7l3.9-3.9M9.7 14.3l-3.9 3.9"/>',
    // Área contaminada: esporos
    'contaminated' => '<circle cx="8.2" cy="9" r="2.6"/><circle cx="15.6" cy="8" r="2"/><circle cx="12.4" cy="15.4" r="3"/><circle cx="6.6" cy="16.2" r="1.6"/><circle cx="17.8" cy="14.6" r="1.5"/>',
    // Atividade humana: alvo de sinal
    'human'      => '<circle cx="12" cy="12" r="2.4"/><circle cx="12" cy="12" r="6" fill="none" stroke="currentColor" stroke-width="1.6"/><path fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" d="M12 2.6v3M12 18.4v3M2.6 12h3M18.4 12h3"/>',
    // Safe Zone: portão protegido (perímetro civil)
    'safe-zone'  => '<path fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" d="M3.6 20.4V9.6L12 4.2l8.4 5.4v10.8z"/><path d="M12 8.2 8.4 9.6v3c0 2.2 1.5 4.2 3.6 4.8 2.1-.6 3.6-2.6 3.6-4.8v-3z"/>',
    // Posto de combustível: bomba
    'fuel'       => '<path fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" d="M4 21.4V4.6c0-1.1.9-2 2-2h4.8c1.1 0 2 .9 2 2v16.8z"/><path d="M6.2 5.6h4.4v3.6H6.2z"/><path fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" d="M12.8 8.4h3.4c.9 0 1.6.7 1.6 1.6v6.4a1.6 1.6 0 0 0 3.2 0V11M17.6 6.6l2.6 2.4"/><path fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" d="M2.6 21.4h11.6"/>',
    // Mercado: toldo + caixa
    'market'     => '<path d="M2.6 4.6h18.8l1.2 4.2c-.5 1.1-1.5 1.8-2.8 1.8s-2.3-.7-2.8-1.8c-.5 1.1-1.5 1.8-2.8 1.8s-2.3-.7-2.8-1.8c-.5 1.1-1.5 1.8-2.8 1.8S6.3 9.9 5.8 8.8C5.3 9.9 4.3 10.6 3 10.6c-.5 0-1-.1-1.4-.3z"/><path fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" d="M4.4 11.8v9.6h15.2v-9.6"/><path d="M9 15h6v6.4H9z"/>',
    // Hospital de campanha: cruz
    'hospital'   => '<path fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" d="M3.4 8.2 12 3.2l8.6 5v12.6H3.4z"/><path d="M10.4 10.4h3.2v2.6h2.6v3.2h-2.6v2.6h-3.2v-2.6H7.8v-3.2h2.6z"/>',
    // Janela logística: comboio/caminhão
    'convoy'     => '<path d="M2.6 7h10.8v8.2H2.6z"/><path fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" d="M13.4 9.4h4.4l3.6 3.4v2.4h-8z"/><circle cx="6.4" cy="17.4" r="2"/><circle cx="16.8" cy="17.4" r="2"/>',
];
