import assert from 'node:assert/strict';
import { test } from 'node:test';
import { renderToStaticMarkup } from 'react-dom/server';
import { formatEligibilityRule, formatSalaryRange } from '../../resources/js/Components/public/transferPresentation';
import PublicFlash from '../../resources/js/Components/public/PublicFlash';

/*
 * The public transfer pages present data the admin side stores in structured
 * form. A rule is `{ type, operator, value }`; an older announcement may still
 * carry plain strings. Rendering either must never crash the page.
 */

const labels: Record<string, string> = {
    'transfers.ruleEmploymentStatus': 'Employment status',
    'transfers.ruleCurrentGrade': 'Current grade',
    'transfers.ruleMinimumService': 'Minimum months of service',
    'transfers.ruleEquals': 'is',
    'transfers.ruleIn': 'is one of',
    'transfers.ruleAtLeast': 'at least',
};
const t = (key: string): string => labels[key] ?? key;

test('a structured eligibility rule is rendered as a localized sentence', () => {
    assert.equal(
        formatEligibilityRule({ type: 'employment_status', operator: 'equals', value: 'active' }, t),
        'Employment status is active',
    );
    assert.equal(
        formatEligibilityRule({ type: 'minimum_service_months', operator: 'greater_than_or_equal', value: '24' }, t),
        'Minimum months of service at least 24',
    );
    assert.equal(
        formatEligibilityRule({ type: 'current_grade', operator: 'in', value: 'X, XI' }, t),
        'Current grade is one of X, XI',
    );
});

test('a plain-text rule and an unknown rule type still render as text', () => {
    assert.equal(formatEligibilityRule('No pending disciplinary case', t), 'No pending disciplinary case');
    assert.equal(
        formatEligibilityRule({ type: 'custom_thing', operator: 'equals', value: '7' }, t),
        'custom thing is 7',
    );
});

test('a salary range is formatted with thousands separators and no decimals', () => {
    assert.equal(formatSalaryRange('9000.00', '12500.00', 'en'), '9,000 – 12,500');
    assert.equal(formatSalaryRange('9000.00', null, 'en'), '9,000');
    assert.equal(formatSalaryRange(null, '12500', 'en'), '12,500');
    assert.equal(formatSalaryRange(null, null, 'en'), null);
});

test('the flash renders nothing when the shared prop carries no message', () => {
    assert.equal(renderToStaticMarkup(<PublicFlash flash={{ message: null, type: null }} />), '');
    assert.equal(renderToStaticMarkup(<PublicFlash flash={null} />), '');
});

test('the flash announces an error as an alert and a success as a status', () => {
    const error = renderToStaticMarkup(<PublicFlash flash={{ message: 'No profile', type: 'error' }} />);
    assert.match(error, /role="alert"/);
    assert.match(error, /No profile/);

    const success = renderToStaticMarkup(<PublicFlash flash={{ message: 'Sent', type: 'success' }} />);
    assert.match(success, /role="status"/);
});
