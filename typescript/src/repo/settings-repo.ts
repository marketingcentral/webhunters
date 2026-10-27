/**
 * Leituras de `admin_settings` e `server_regions` que o jogo usa fora do painel.
 * (App\Repo\AdminSettingsRepo::valor e App\Repo\ServersRepo::rodape do PHP.)
 */
import { memo } from '../core/context';
import * as DB from '../core/database';
import type { Dict } from '../core/view';

/** Valor tipado de uma chave de /admin/configuracoes; falha de banco devolve o padrão. */
export async function valor<T>(chave: string, padrao: T): Promise<T | string | number | boolean> {
  let linha: Dict | null;
  try {
    linha = await DB.one('SELECT "value", kind FROM admin_settings WHERE "key" = :k', { k: chave });
  } catch (e) {
    console.error(`[extracthunters] settings.valor(${chave}):`, e);
    return padrao;
  }
  if (linha === null || linha.value === null || linha.value === undefined) return padrao;
  const bruto = String(linha.value);
  switch (String(linha.kind)) {
    case 'int':
      return /^-?\d+(\.\d+)?$/.test(bruto) ? parseInt(bruto, 10) : padrao;
    case 'bool':
      return bruto === '1';
    case 'json':
      try {
        return (JSON.parse(bruto) as T) ?? padrao;
      } catch {
        return padrao;
      }
    default:
      return bruto;
  }
}

/** Região/ping/situação do rodapé, da mesma tabela que /admin/servidores escreve. */
export function rodape(): Promise<Dict> {
  return memo('servers.rodape', async () => {
    let r: Dict | null;
    try {
      r = await DB.one('SELECT label, ping_ms, state FROM server_regions ORDER BY sort_order, id LIMIT 1');
    } catch {
      return {};
    }
    if (r === null) return {};
    const estado = String(r.state);
    return {
      region: String(r.label),
      ping_ms: Number(r.ping_ms),
      status: estado === 'down' ? 'offline' : 'online',
      bars: estado === 'down' ? 0 : estado === 'warn' ? 2 : 4,
    };
  });
}
