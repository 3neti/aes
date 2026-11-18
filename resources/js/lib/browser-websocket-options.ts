export function getBrowserWebSocketOptions(
    location: Pick<Location, 'hostname' | 'port' | 'protocol'>,
) {
    return {
        wsHost: location.hostname,
        wsPort: Number(location.port || 80),
        wssPort: Number(location.port || 443),
        forceTLS: location.protocol === 'https:',
    };
}
