import { expiryFromPreset } from '@/lib/expiry';

describe('@/lib/expiry.ts', function () {
    describe('expiryFromPreset()', function () {
        const now = new Date(2026, 9, 15, 13, 30, 0);

        it('should not expire keys created with "never"', function () {
            expect(expiryFromPreset('never', '', now)).toBeNull();
        });

        it('should add the number of days for the fixed presets', function () {
            expect(expiryFromPreset('7', '', now)).toEqual(new Date(2026, 9, 22, 13, 30, 0));
            expect(expiryFromPreset('30', '', now)).toEqual(new Date(2026, 10, 14, 13, 30, 0));
            expect(expiryFromPreset('90', '', now)).toEqual(new Date(2027, 0, 13, 13, 30, 0));
        });

        it('should ignore the custom date unless the custom preset is chosen', function () {
            expect(expiryFromPreset('7', '2030-01-01', now)).toEqual(new Date(2026, 9, 22, 13, 30, 0));
        });

        it('should expire custom dates at the end of that day', function () {
            expect(expiryFromPreset('custom', '2026-12-31', now)).toEqual(new Date(2026, 11, 31, 23, 59, 59, 999));
            expect(expiryFromPreset('custom', '2026-10-15', now)).toEqual(new Date(2026, 9, 15, 23, 59, 59, 999));
        });

        it('should reject missing, invalid or past custom dates', function () {
            expect(expiryFromPreset('custom', '', now)).toBeUndefined();
            expect(expiryFromPreset('custom', 'tomorrow', now)).toBeUndefined();
            expect(expiryFromPreset('custom', '2026-02-30', now)).toBeUndefined();
            expect(expiryFromPreset('custom', '2026-10-14', now)).toBeUndefined();
        });
    });
});
