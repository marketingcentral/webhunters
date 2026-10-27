/** Interrompe o ciclo da requisição numa página de erro (404, 405, 419, 503...). */
export class HttpException extends Error {
  constructor(
    public readonly status: number,
    message = '',
    public readonly headers: Record<string, string> = {},
  ) {
    super(message);
  }
}
