import http from '@/api/http';
import { ApiKey, rawDataToApiKey } from '@/api/account/getApiKeys';

export default (
    description: string,
    allowedIps: string,
    expiresAt: Date | null = null
): Promise<ApiKey & { secretToken: string }> => {
    return new Promise((resolve, reject) => {
        http.post('/api/client/account/api-keys', {
            description,
            allowed_ips: allowedIps.length > 0 ? allowedIps.split('\n') : [],
            expires_at: expiresAt ? expiresAt.toISOString() : null,
        })
            .then(({ data }) =>
                resolve({
                    ...rawDataToApiKey(data.attributes),
                    // eslint-disable-next-line camelcase
                    secretToken: data.meta?.secret_token ?? '',
                })
            )
            .catch(reject);
    });
};
