import { describe, expect, it } from 'vitest';
import type { ProductForm } from '../lib/product-validation';
import { blankProductForm, validateProduct } from '../lib/product-validation';

const form = (overrides: Partial<ProductForm> = {}): ProductForm => ({
    ...blankProductForm(),
    title: 'Ceramic Mug, 12 oz',
    sku: 'mug-cer-12',
    ...overrides,
});

describe('validateProduct', () => {
    it('accepts the defaults with a title and SKU, in integer cents', () => {
        const { errors, payload } = validateProduct(form());
        expect(errors).toEqual({});
        expect(payload).toMatchObject({
            title: 'Ceramic Mug, 12 oz',
            sku: 'MUG-CER-12', // normalised like the server
            cost: 1000,
            fees: 350,
            price: 2499,
            floor: 1650,
            competitors: ['penny_pincher'],
        });
    });

    it('checks the floor against this product’s own cost and fees', () => {
        // 10.00 + 3.50 + 2.00 = 15.50 margin floor
        expect(validateProduct(form({ floor: '15.49' })).errors.floor).toMatch(
            /below the margin floor \(\$15\.50/,
        );
        expect(
            validateProduct(form({ floor: '15.50', price: '20.00' })).errors,
        ).toEqual({});
        expect(validateProduct(form({ cost: '12.00' })).errors.floor).toMatch(
            /below the margin floor/,
        );
    });

    it('keeps the starting price between floor and ceiling', () => {
        expect(validateProduct(form({ price: '40.00' })).errors.price).toMatch(
            /must be between the floor/,
        );
    });

    it('rejects bad titles, SKUs, money and too many competitors', () => {
        const { errors, payload } = validateProduct(
            form({
                title: 'ab',
                sku: 'my mug',
                shipping: '1.234',
                competitors: ['anchor', 'matcher', 'chaos'],
            }),
        );
        expect(payload).toBeNull();
        expect(Object.keys(errors).sort()).toEqual([
            'competitors',
            'shipping',
            'sku',
            'title',
        ]);
    });
});
