import { httpErrorToHuman } from '@/api/http';

describe('@/api/http.ts', function () {
    describe('httpErrorToHuman()', function () {
        it('should describe rate limited responses using Retry-After', function () {
            const error = {
                message: 'Request failed with status code 429',
                response: {
                    status: 429,
                    headers: { 'retry-after': '30' },
                    data: {
                        errors: [{ code: 'TooManyRequestsHttpException', status: '429', detail: 'Too Many Attempts.' }],
                    },
                },
            };

            expect(httpErrorToHuman(error)).toBe('Too many attempts. Try again in 30 seconds.');
        });

        it('should keep using the API error detail for other responses', function () {
            const error = {
                message: 'Request failed with status code 422',
                response: { status: 422, headers: {}, data: { errors: [{ detail: 'The name field is required.' }] } },
            };

            expect(httpErrorToHuman(error)).toBe('The name field is required.');
        });
    });
});
