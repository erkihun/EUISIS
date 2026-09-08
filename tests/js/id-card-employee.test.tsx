import assert from 'node:assert/strict';
import { test } from 'node:test';
import { renderToStaticMarkup } from 'react-dom/server';
import { mapCardEmployee } from '../../resources/js/Components/IdCards/mapCardEmployee';
import IdCardBilingualField, { buildBilingualFields } from '../../resources/js/Components/IdCards/IdCardBilingualField';

const card = {
    card_number: 'CARD-42',
    public_card_uuid: 'identity-must-not-change',
    employee: {
        full_name: 'ሰላም ታደሰ', name_en: 'Selam Tadesse',
        status: 'active', employment_type: 'contract', gender: 'female',
        date_of_birth: '1990-03-15T00:00:00.000000Z',
        nationality: 'Ethiopian', phone: '+251911223344', photo_url: '/storage/photos/real.png',
        employee_number: 'INTERNAL-EMP-42', national_id: 'SECRET',
    },
};

test('maps real identity fields without mutating the employee or card', () => {
    const before = structuredClone(card);
    assert.deepEqual(mapCardEmployee(card), {
        cardNumber: 'CARD-42', fullName: 'Selam Tadesse', fullNameAm: 'ሰላም ታደሰ',
        gender: 'female', dateOfBirth: '15 Mar 1990', dateOfBirthAm: 'መጋቢት 6, 1982',
        nationality: 'Ethiopian', employmentStatus: 'contract',
        phoneNumber: '+251911223344', photoUrl: '/storage/photos/real.png',
    });
    assert.deepEqual(card, before);
    assert.deepEqual(buildBilingualFields(mapCardEmployee(card)).map((field) => field.key),
        ['name', 'sex', 'dob', 'nationality', 'employment', 'phone', 'idNumber']);
});

for (const [type, en, am] of [
    ['permanent', 'Permanent', 'ቋሚ'], ['contract', 'Contract', 'ኮንትራት'],
    ['temporary', 'Temporary', 'ጊዜያዊ'], ['probation', 'Probation', 'የሙከራ ጊዜ'],
    ['daily_labor', 'Daily Labor / Casual', 'የቀን ሰራተኛ'],
    ['intern', 'Intern', 'ተለማማጅ'], ['other', 'Other', 'ሌላ'],
]) {
    test(`renders ${type} in both languages`, () => {
        const fields = buildBilingualFields(mapCardEmployee({
            ...card, employee: { ...card.employee, employment_type: type },
        }));
        assert.equal(fields[4].valueEn, en);
        assert.equal(fields[4].valueAm, am);
    });
}

test('never substitutes account status for a missing employment type', () => {
    const fields = buildBilingualFields(mapCardEmployee({
        ...card, employee: { ...card.employee, employment_type: null },
    }));
    assert.equal(fields[4].valueEn, null);
    assert.equal(fields[4].valueAm, null);
    assert.equal(buildBilingualFields({ cardNumber: 'CARD-42', employmentStatus: 'active' })[4].valueEn, null);
});

test('preserves an explicitly saved legacy Amharic translation', () => {
    const fields = mapCardEmployee({ ...card, employee: {
        ...card.employee, full_name: 'Selam Tadesse', metadata: { name_am: 'ሰላም ታደሰ' },
    } });
    assert.equal(fields.fullNameAm, 'ሰላም ታደሰ');
    assert.equal(fields.fullName, 'Selam Tadesse');
});

test('renders an Amharic label and value before each English label and value', () => {
    const fields = buildBilingualFields(mapCardEmployee(card));
    assert.equal(fields[3].valueAm, 'ኢትዮጵያዊ');
    for (const field of fields) {
        const { key, ...rows } = field;
        const markup = renderToStaticMarkup(<IdCardBilingualField key={key} {...rows}
            labelStyle={{ color: '#123456', fontSize: '8px', fontWeight: 400 }}
            valueStyle={{ color: '#654321', fontSize: '10px', fontWeight: 700 }} />);
        assert.ok(markup.indexOf(field.labelAm) < markup.indexOf(field.labelEn));
        assert.ok(markup.indexOf(field.valueAm!) < markup.indexOf(field.labelEn));
        assert.ok(markup.lastIndexOf(field.valueEn!) > markup.indexOf(field.labelEn));
        assert.ok(!markup.includes(' | '));
        assert.ok(markup.includes('color:#123456;font-size:8px;font-weight:400'));
        assert.ok(markup.includes('color:#654321;font-size:10px;font-weight:700'));
    }
});
