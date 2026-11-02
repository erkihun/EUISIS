// Builds the registration test-case document.
// Usage: node build.cjs <am|en|bi> <out.docx>
//   am — Amharic, en — English, bi — Amharic with the English beneath each line.
const fs = require('fs');
const {
  Document, Packer, Paragraph, TextRun, Table, TableRow, TableCell, WidthType, AlignmentType,
  PageOrientation, ShadingType, BorderStyle, HeadingLevel, Footer, Header, PageNumber,
  TableOfContents, PageBreak, VerticalAlign,
} = require('docx');
const base = Object.fromEntries(require('./cases.cjs').map((s) => [s.id, s]));
const more = require('./cases-structure.cjs');
const codeRules = require('./cases-coderules.cjs');
const en = { ...require('./cases-en.cjs') };
en.CR = codeRules.en.CR;
en.CRL = codeRules.en.CRL;
en.DQ = { ...en.DQ, cases: { ...en.DQ.cases, 'TC-DQ-09': codeRules.en.dq } };

const MODE = process.argv[2];
if (!['am', 'en', 'bi'].includes(MODE)) throw new Error('mode must be am, en or bi');
const VERSION = '1.4';

// A bilingual string; rendered as one language or as both.
const t = (am, english) => ({ am, en: english });
const pick = (s) => (typeof s === 'string' ? s : MODE === 'en' ? s.en : s.am);
const flat = (s) => (typeof s === 'string' ? s : MODE === 'bi' ? `${s.am} / ${s.en}` : pick(s));

// Every case by its source id, with the section it was written under.
base.DQ.cases.push(...more.dataQuality, codeRules.am.dq);
const sources = [...Object.values(base), more.orgTypes, more.hierarchyVersions, more.unitTypes, more.units, more.reportingLines, codeRules.am.CR, codeRules.am.CRL];
const byId = {};
for (const s of sources) for (const c of s.cases) byId[c[0]] = { sectionId: s.id, c };
const intros = Object.fromEntries(sources.map((s) => [s.id, t(s.intro, en[s.id].intro)]));

// Execution order follows what each step depends on: a type before the
// institutions that use it, a hierarchy version before any link, an
// institution before its units, a unit type before units, units before
// reporting lines. A rule that needs registered data is tested only after
// that data exists.
const range = (prefix, n) => Array.from({ length: n }, (_, i) => `TC-${prefix}-${String(i + 1).padStart(2, '0')}`);
const ORDER = [
  [t('ቅድመ ዝግጅት (የሙከራ መለያዎች)', 'Prerequisites (test accounts)'),
    t('ምዝገባ ከመጀመሩ በፊት መኖር ያለባቸው የሙከራ መለያዎች።', 'The test accounts that must exist before registration starts.'),
    ['TC-SET-05']],
  [t('የኮድ ደንቦች', 'Code rules'), intros.CR, ['TC-SET-03', 'TC-CR-01', 'TC-CR-02', 'TC-CR-03']],
  // Every type exists before any parent rule names it.
  [t('የተቋም ዓይነቶች', 'Organization types'), intros.ORT,
    ['TC-ORT-01', 'TC-SET-01', 'TC-ORT-02', 'TC-ORT-03', 'TC-SET-02', 'TC-ORT-05', 'TC-ORT-07']],
  [t('የመጀመሪያው የተዋረድ ስሪት (ረቂቅ)', 'The first hierarchy version (draft)'),
    t('ተቋማትን ከበላያቸው ጋር ለማገናኘት ረቂቅ ስሪት ያስፈልጋል፤ ስለዚህ ከተቋማት ምዝገባ በፊት ይፈጠራል።', 'Linking institutions to their parents needs a draft version, so it is created before institutions are registered.'),
    ['TC-HV-01', 'TC-HV-02', 'TC-HV-03', 'TC-HIE-10']],
  // Only the top institution is registered without a parent; the others
  // are registered under their parents in the hierarchy section.
  [t('የመጀመሪያውን ተቋም መመዝገብ', 'Registering the first institution'),
    t('የመዋቅሩ የበላይ ተቋም (ለምሳሌ የከተማ አስተዳደሩ) ያለ የበላይ ይመዘገባል፤ የምዝገባ ቅጹ የሚሞከረው በእሱና በጊዜያዊ መዝገቦች ነው። ሌሎቹ ተቋማት በበላያቸው ሥር የሚመዘገቡት በተዋረድ ምደባ ክፍል ነው።', 'The institution at the top of the structure (for example the city administration) is registered without a parent; the registration form is tested with it and with temporary records. The other institutions are registered under their parents in the hierarchy placement section.'),
    ['TC-CR-04', ...range('REG', 8)]],
  // Later tests use a deactivated or an archived temporary record.
  [t('ጊዜያዊ መዝገብን ማቦዘን፣ ወደ ማህደር ማስገባትና መሰረዝ', 'Deactivating, archiving and deleting a temporary record'),
    t('ቀጣይ ሙከራዎች ንቁ ያልሆነ ወይም በማህደር ያለ ጊዜያዊ መዝገብ ስለሚጠቀሙ ማቦዘንና ማህደር እዚህ ይቀድማሉ።', 'Later tests use an inactive or archived temporary record, so deactivating and archiving come first.'),
    ['TC-LIF-01', 'TC-LIF-03', 'TC-LIF-04']],
  [t('የመረጃ ትክክለኛነት ማረጋገጫ', 'Data validation'), intros.VAL, range('VAL', 12)],
  [t('የተዋረድ ምደባና ማተም', 'Hierarchy placement and publication'), intros.HIE,
    ['TC-HIE-01', 'TC-HIE-03', 'TC-HIE-05', 'TC-HIE-06', 'TC-EDT-04', 'TC-HV-05', 'TC-HIE-07', 'TC-HIE-08', 'TC-HIE-04']],
  [t('ከታተመ በኋላ የተዋረድ ስሪቶችን መጠበቅ', 'Maintaining hierarchy versions after publication'),
    t('የመጀመሪያው ስሪት ከታተመ በኋላ መዋቅሩ የሚቀየረው በአዲስ ረቂቅ ብቻ ነው።', 'After the first version is published, the structure changes only through a new draft.'),
    ['TC-HV-04', 'TC-HIE-02', 'TC-SET-04', 'TC-HIE-09', 'TC-HV-07', 'TC-HV-06', 'TC-HV-08', 'TC-HV-09']],
  [t('ጥቅም ላይ የዋሉ ዓይነቶችና ተቋማት', 'Types and institutions already in use'),
    t('ተቋማት ከተመዘገቡና ተዋረዱ ከታተመ በኋላ ብቻ ሊሞከሩ የሚችሉ ደንቦች።', 'Rules that can be tested only once institutions are registered and the hierarchy is published.'),
    ['TC-ORT-04', 'TC-ORT-06', 'TC-LIF-02']],
  [t('ኮዶች ከተሰጡ በኋላ የኮድ ደንቦች', 'Code rules once codes have been issued'), intros.CRL,
    ['TC-CR-06', 'TC-CR-07', 'TC-CR-08', 'TC-CR-09', 'TC-CR-10', 'TC-CR-11', 'TC-CR-13', 'TC-CR-14']],
  [t('ፈቃድና የሥራ ወሰን', 'Permissions and scope'), intros.SEC, range('SEC', 4)],
  [t('የሥራ ክፍል ዓይነቶች', 'Organization unit types'), intros.UT, ['TC-UT-01', 'TC-UT-02', 'TC-UT-05']],
  [t('የሥራ ክፍሎች (የተቋም ውስጣዊ መዋቅር)', 'Organization units (internal structure)'),
    t(`${intros.UNIT.am} የሥራ ክፍል ዓይነቶችን የሚመለከቱ ቀሪ ደንቦችም ክፍሎች ከተፈጠሩ በኋላ እዚህ ይሞከራሉ።`, `${intros.UNIT.en} The remaining unit-type rules are tested here, once units exist.`),
    ['TC-UNIT-01', 'TC-UNIT-02', 'TC-UNIT-03', 'TC-UNIT-04', 'TC-UNIT-05', 'TC-UNIT-06', 'TC-UNIT-07', 'TC-UNIT-08', 'TC-UNIT-11', 'TC-UNIT-12', 'TC-UNIT-13', 'TC-UT-03', 'TC-UT-04', 'TC-CR-05']],
  [t('የተጠሪነት መስመሮች', 'Reporting lines'), intros.RL, range('RL', 9)],
  [t('የተመዘገበ ተቋምን ማስተካከል', 'Editing a registered organization'), intros.EDT,
    ['TC-EDT-01', 'TC-EDT-02', 'TC-EDT-03', 'TC-EDT-05', 'TC-EDT-06', 'TC-EDT-07']],
  [t('በኤክሴል መዋቅር ማስገባት («መዋቅር አስመጣ»)', 'Importing a structure from Excel («Import Structure»)'), intros.IMP, [...range('IMP', 9), 'TC-CR-12']],
  // Positions and employees first exist once a structure is imported.
  [t('የሥራ መደብና ሠራተኛ ያላቸው የሥራ ክፍሎች', 'Units with positions and employees'),
    t('የሥራ መደቦችና ሠራተኞች የሚገኙት መዋቅር ከገባ በኋላ ነው፤ ስለዚህ እነሱን የሚጠይቁ የሥራ ክፍል ደንቦች እዚህ ይሞከራሉ።', 'Positions and employees exist once a structure has been imported, so the unit rules that need them are tested here.'),
    ['TC-UNIT-09', 'TC-UNIT-10']],
  [t('ከትክክለኛው ምዝገባ በኋላ የሚደረግ የመረጃ ማረጋገጫ', 'Data check after the real registration'), intros.DQ, range('DQ', 9)],
];

// New ids follow the execution order (TC-<section>-<case>), and every
// reference to an old id inside the text follows them.
const used = ORDER.flatMap(([, , ids]) => ids);
const missing = Object.keys(byId).filter((id) => !used.includes(id));
const twice = used.filter((id, i) => used.indexOf(id) !== i);
if (missing.length || twice.length || used.some((id) => !byId[id])) {
  throw new Error(`order mismatch: missing ${missing} twice ${twice} unknown ${used.filter((id) => !byId[id])}`);
}
const secOf = (id) => ORDER.findIndex(([, , ids]) => ids.includes(id)) + 1;
const newId = {};
ORDER.forEach(([, , ids], si) => ids.forEach((id, ci) => {
  newId[id] = `TC-${String(si + 1).padStart(2, '0')}-${String(ci + 1).padStart(2, '0')}`;
}));
const relabel = (text) => text.replace(/TC-[A-Z]+-\d{2}/g, (id) => newId[id] ?? id);
const tr = (am, english) => t(relabel(am), relabel(english));

const sections = ORDER.map(([title, intro, ids], i) => ({
  title: t(`ክፍል ${i + 1}፦ ${title.am}`, `Section ${i + 1}: ${title.en}`),
  intro,
  cases: ids.map((id) => {
    const { sectionId, c: [, title, pre, steps, expected] } = byId[id];
    const [eTitle, ePre, eSteps, eExpected] = en[sectionId].cases[id];
    return {
      id: newId[id],
      title: tr(title, eTitle),
      pre: tr(pre, ePre),
      steps: steps.map((st, k) => tr(`${k + 1}. ${st}`, `${k + 1}. ${eSteps[k]}`)),
      expected: expected.map((x, k) => tr(x, eExpected[k])),
    };
  }),
}));
const total = sections.reduce((n, s) => n + s.cases.length, 0);

const AM_FONT = 'Nyala';
const EN_FONT = 'Calibri';
const BRAND = '1F3A5F';
const LIGHT = 'E8EEF5';
const MUTED = '5B6B80';

// One paragraph per language present. In bilingual mode the English line is
// smaller and muted so the Amharic leads.
function paras(s, o = {}) {
  const size = o.size ?? 22;
  const mk = (text, lang, extra = {}) => new Paragraph({
    alignment: o.align,
    spacing: { after: extra.after ?? o.after ?? 80, before: o.before ?? 0, line: 264 },
    keepNext: o.keepNext,
    children: [new TextRun({
      text, font: lang === 'en' ? EN_FONT : AM_FONT,
      size: extra.size ?? (lang === 'en' && MODE !== 'en' ? size - 2 : size),
      bold: o.bold, italics: extra.italics ?? o.italics, color: extra.color ?? o.color,
    })],
  });
  if (typeof s === 'string') return [mk(s, MODE === 'en' ? 'en' : 'am')];
  if (MODE === 'am') return [mk(s.am, 'am')];
  if (MODE === 'en') return [mk(s.en, 'en')];
  return [
    mk(s.am, 'am', { after: 10 }),
    mk(s.en, 'en', { color: o.color === 'FFFFFF' ? 'DCE4EE' : (o.color ?? MUTED), italics: true }),
  ];
}

const border = { style: BorderStyle.SINGLE, size: 4, color: '9AA9BC' };
const borders = { top: border, bottom: border, left: border, right: border };

function cell(content, width, o = {}) {
  const lines = Array.isArray(content) ? content : [content];
  return new TableCell({
    width: { size: width, type: WidthType.DXA },
    borders,
    shading: o.fill ? { type: ShadingType.CLEAR, color: 'auto', fill: o.fill } : undefined,
    verticalAlign: VerticalAlign.TOP,
    margins: { top: 60, bottom: 60, left: 90, right: 90 },
    children: lines.flatMap((l) => paras(l, { size: o.size ?? 19, bold: o.bold, color: o.color, after: 50 })),
  });
}

function table(widths, rows) {
  return new Table({
    width: { size: widths.reduce((a, b) => a + b, 0), type: WidthType.DXA },
    columnWidths: widths,
    rows: rows.map((r, i) => new TableRow({
      tableHeader: i === 0,
      cantSplit: true,
      children: r.map((c, j) => (i === 0
        ? cell(c, widths[j], { fill: BRAND, bold: true, color: 'FFFFFF' })
        : cell(c, widths[j], { fill: j === 0 ? LIGHT : undefined, bold: j === 0 }))),
    })),
  });
}

// A heading that opens a new page says so itself; a separate page-break
// paragraph leaves a blank page whenever the content above ends exactly at
// the bottom of a page.
const heading = (s, level, size, newPage) => new Paragraph({
  heading: level, pageBreakBefore: newPage,
  spacing: { before: level === HeadingLevel.HEADING_1 ? 240 : 200, after: 110 }, keepNext: true,
  children: [new TextRun({ text: flat(s), font: MODE === 'en' ? EN_FONT : AM_FONT, size, bold: true, color: BRAND })],
});
const h1 = (s, newPage = false) => heading(s, HeadingLevel.HEADING_1, 30, newPage);
const h2 = (s, newPage = false) => heading(s, HeadingLevel.HEADING_2, 26, newPage);
const pageBreak = () => new Paragraph({ children: [new PageBreak()] });

// ── Text ─────────────────────────────────────────────────────────────────
const S = {
  system: t('አገልጋይ (Agelgay) — የሰው ሀብትና የአገልግሎት አስተዳደር ሥርዓት', 'Agelgay — human resources and service management system'),
  title: t('የተቋማት ምዝገባ', 'Institution Registration'),
  subtitle: t('የሙከራ ሁኔታዎች', 'Test Cases'),
  purposeLine: t('ተቋማት ወደ ምርት (production) ሥርዓቱ ከመመዝገባቸው በፊትና በምዝገባው ወቅት የሚደረግ ሙከራ', 'Testing before and during the registration of institutions in the production system'),
  date: t('ጥቅምት 22 ቀን 2019 ዓ.ም.', '1 November 2026'),
  docTitle: t('የተቋማት ምዝገባ — የሙከራ ሁኔታዎች', 'Institution Registration — Test Cases'),
};
const versionLine = t(`ስሪት ${VERSION}  •  ጥቅምት 22 ቀን 2019 ዓ.ም. (01 November 2026)`, `Version ${VERSION}  •  1 November 2026 (ጥቅምት 22 ቀን 2019 ዓ.ም.)`);

// ── Cover ────────────────────────────────────────────────────────────────
const cover = [
  ...paras('', { after: 1500 }),
  ...paras(S.system, { size: 28, color: '4A5B70', align: AlignmentType.CENTER }),
  ...paras(S.title, { size: 56, bold: true, color: BRAND, align: AlignmentType.CENTER, after: 120 }),
  ...paras(S.subtitle, { size: 40, color: BRAND, align: AlignmentType.CENTER, after: 500 }),
  ...paras(S.purposeLine, { size: 24, align: AlignmentType.CENTER, after: 1000 }),
  ...paras(versionLine, { size: 22, color: '4A5B70', align: AlignmentType.CENTER }),
  pageBreak(),
];

// ── Document control ─────────────────────────────────────────────────────
const control = [
  h1(t('የሰነድ ቁጥጥር', 'Document control')),
  table([3200, 12198], [
    [t('መረጃ', 'Item'), t('ዝርዝር', 'Detail')],
    [t('የሰነዱ ርዕስ', 'Title'), S.docTitle],
    [t('ስሪት', 'Version'), VERSION],
    [t('የተዘጋጀበት ቀን', 'Date'), S.date],
    [t('የሚመለከተው', 'Scope'), t(
      'የተቋም ዓይነቶች፣ የተዋረድ ስሪቶች፣ የተቋማት ምዝገባና የተዋረድ ምደባ፣ የሥራ ክፍል ዓይነቶች፣ የሥራ ክፍሎች፣ የተጠሪነት መስመሮች፣ ማስተካከያ፣ ማቦዘንና ማህደር፣ ፈቃድና ወሰን፣ በኤክሴል መዋቅር ማስገባት',
      'Organization types, hierarchy versions, organization registration and hierarchy placement, unit types, organization units, reporting lines, editing, deactivation and archiving, permissions and scope, structure import from Excel',
    )],
    [t('ያዘጋጀው', 'Prepared by'), ''],
    [t('የገመገመው', 'Reviewed by'), ''],
    [t('ያጸደቀው', 'Approved by'), ''],
  ]),
  ...paras('', { after: 200 }),
  h1(t('ማውጫ', 'Contents')),
  new TableOfContents(flat(t('ማውጫ', 'Contents')), { hyperlink: true, headingStyleRange: '1-2' }),
  ...paras(t('ማስታወሻ፦ ማውጫውን ለማደስ በWord ውስጥ ማውጫው ላይ ቀኝ ጠቅ አድርገው «Update Field» ይምረጡ።', 'Note: to refresh the contents in Word, right-click it and choose «Update Field».'), { size: 18, italics: true, color: '6B7A8C' }),
];

// ── Introduction and prerequisites ───────────────────────────────────────
const last = sections.length;
const intro = [
  h1(t('1. ዓላማና አጠቃቀም', '1. Purpose and use'), true),
  ...paras(t(
    `ተቋማት አንድ ጊዜ ከተመዘገቡና ሠራተኞች፣ የሥራ ክፍሎች፣ የሥራ መደቦችና ሌሎች መረጃዎች ከእነሱ ጋር ከተገናኙ በኋላ መለወጥ አስቸጋሪ ነው። ይህ ሰነድ ምዝገባው ከመጀመሩ በፊትና በምዝገባው ወቅት ሥርዓቱ በትክክል መሥራቱን ለማረጋገጥ የሚደረጉ ${total} የሙከራ ሁኔታዎችን ይዟል።`,
    `Once institutions are registered and employees, units, positions and other records are linked to them, they are hard to change. This document holds ${total} test cases that confirm the system works correctly before and during registration.`,
  )),
  ...paras(t(
    'እያንዳንዱ የሙከራ ሁኔታ መለያ ቁጥር፣ ርዕስ፣ ቅድመ ሁኔታ፣ የሚከናወኑ ደረጃዎችና የሚጠበቅ ውጤት አለው። ሞካሪው ደረጃዎቹን በቅደም ተከተል ፈጽሞ የተገኘውን ውጤት በ«ውጤት» ዓምድ ውስጥ ይመዘግባል።',
    'Each test case has an ID, a title, a precondition, the steps to perform and the expected result. The tester performs the steps in order and records the outcome in the «Result» column.',
  )),
  h2(t('1.1 ውጤት አመዘጋገብ', '1.1 Recording results')),
  table([2600, 12798], [
    [t('ምልክት', 'Mark'), t('ትርጉም', 'Meaning')],
    [t('አለፈ (✓)', 'Pass (✓)'), t('የተገኘው ውጤት ከሚጠበቀው ውጤት ጋር ሙሉ በሙሉ ይጣጣማል።', 'The actual result fully matches the expected result.')],
    [t('ወደቀ (✗)', 'Fail (✗)'), t('የተገኘው ውጤት ከሚጠበቀው ይለያል። ዝርዝሩን በ«ማስታወሻ» ዓምድና በምዕራፍ 5 የጉድለት መመዝገቢያ ቅጽ ይጻፉ።', 'The actual result differs from the expected result. Write the details in the «Notes» column and in the defect log (chapter 5).')],
    [t('ታግዷል (!)', 'Blocked (!)'), t('ቅድመ ሁኔታው ስላልተሟላ ወይም በሌላ ጉድለት ምክንያት ሙከራውን ማከናወን አልተቻለም።', 'The test could not be performed because a precondition was not met or another defect blocked it.')],
    [t('አልተፈጸመም (—)', 'Not run (—)'), t('ሙከራው ገና አልተከናወነም።', 'The test has not been performed yet.')],
  ]),
  h2(t('1.2 የቅደም ተከተል መመሪያ', '1.2 Order of testing')),
  ...[
    t('ክፍሎቹንና በውስጣቸው ያሉትን የሙከራ ሁኔታዎች በተሰጡት ቁጥር ቅደም ተከተል ያከናውኑ። ቅደም ተከተሉ ጥገኝነትን ይከተላል፦ የኮድ ደንብ ሳይኖር ምንም መዝገብ፣ የተቋም ዓይነት ሳይመዘገብ ተቋም፣ የተዋረድ ስሪት ሳይፈጠር የተቋማት ተዋረድ፣ ተቋም ሳይመዘገብ የሥራ ክፍል፣ የሥራ ክፍል ዓይነት ሳይኖር የሥራ ክፍል፣ የሥራ ክፍል ሳይኖር የተጠሪነት መስመር መመዝገብ አይቻልም። የቀደመው ክፍል ሳያልፍ ቀጣዩን አይጀምሩ።',
      'Run the sections, and the test cases inside them, in the numbered order. The order follows dependencies: no record without its code rule, no institution without its organization type, no institution hierarchy without a hierarchy version, no unit without its institution and unit type, no reporting line without units. Do not start a section before the one before it passes.'),
    t(`ክፍል 2–${last - 1} የሚከናወኑት በምዝገባው ወቅት በሚመዘገቡት ተቋማት ነው። ተቋማቱ አስቀድመው ስለማይታወቁ ሰነዱ በ«ተቋም ሀ»፣ «ክፍል ሀ» እና «ጊዜያዊ መዝገብ» ይጠቅሳቸዋል (2.1 ይመልከቱ)።`,
      `Sections 2–${last - 1} are run on the institutions being registered. Since those are not known in advance, the document refers to them as «Institution A», «Unit A» and «temporary record» (see 2.1).`),
    tr('መዝገብን የሚሰርዙ፣ ወደ ማህደር የሚያስገቡ ወይም ሆን ብለው የተሳሳተ ወይም የተደገመ መረጃ የሚያስገቡ ሙከራዎች በ«ጊዜያዊ መዝገብ» ብቻ ይከናወናሉ፤ ሙከራው ሲያልቅ ይወገዳል (TC-DQ-06)። በሚመዘገቡት ትክክለኛ ተቋማት ላይ አይከናወኑም።',
      'Tests that delete or archive a record, or deliberately enter wrong or duplicate data, use only a «temporary record», removed when testing ends (TC-DQ-06). They are never run on the real institutions being registered.'),
    t(`ክፍል ${last} ትክክለኛው ምዝገባ ከተጠናቀቀ በኋላ፣ ሠራተኞችና ምደባዎች ከመገናኘታቸው በፊት ይከናወናል።`,
      `Section ${last} is run after the real registration is complete, before employees and assignments are linked.`),
    t('የወደቀ የሙከራ ሁኔታ ካለ ትክክለኛውን ምዝገባ ከመጀመርዎ በፊት ጉድለቱ መስተካከሉንና ሙከራው እንደገና ማለፉን ያረጋግጡ።',
      'If a test case fails, make sure the defect is fixed and the test passes again before starting the real registration.'),
  ].flatMap((s, i) => paras(t(`${i + 1}. ${s.am}`, `${i + 1}. ${s.en}`))),
  h1(t('2. ከሙከራው በፊት መሟላት ያለባቸው', '2. Prerequisites'), true),
  table([4200, 11198], [
    [t('ቅድመ ሁኔታ', 'Prerequisite'), t('ዝርዝር', 'Detail')],
    [t('የሙከራ መለያዎች', 'Test accounts'), t(
      `(ሀ) Super Admin ወይም System Admin፣ (ሐ) ተቋማትን ማየት ብቻ የሚችል መለያ። መለያ (ለ) — ለአንድ ተቋም የተመደበ Organizational Admin — ተቋሙ ከተመዘገበ በኋላ በክፍል ${secOf('TC-SEC-02')} ይፈጠራል። የኤክሴል ማስገቢያ ለመሞከር organizations.import ፈቃድ ያስፈልጋል።`,
      `(a) Super Admin or System Admin, (c) an account that can only view organizations. Account (b) — an Organizational Admin assigned to one institution — is created in Section ${secOf('TC-SEC-02')}, once that institution is registered. The Excel import needs the organizations.import permission.`)],
    [t('የኮድ አወጣጥ', 'Numbering'), t(`ተቋሙ የወሰነው የኮድ አወጣጥ (የእያንዳንዱ መዝገብ ቅርጸት)። ደንቦቹ በክፍል ${secOf('TC-CR-01')} ይረጋገጣሉ።`, `The numbering the institution decided (the format for each record type). The rules themselves are checked in Section ${secOf('TC-CR-01')}.`)],
    [t('በሙከራው የሚፈጠሩ', 'Created during testing'), t('የተቋም ዓይነቶች፣ የተዋረድ ስሪቶች፣ የሥራ ክፍል ዓይነቶችና የሥራ ክፍሎች አስቀድመው አይጠበቁም፤ በቅደም ተከተሉ መሠረት በየክፍላቸው ይፈጠራሉ።', 'Organization types, hierarchy versions, unit types and units are not expected in advance; they are created in their own sections, in order.')],
    [t('የሙከራ ፋይሎች', 'Test files'), t('ከ2 ሜጋባይት ያነሰ PNG ሎጎ፣ ከ2 ሜጋባይት በላይ ምስል፣ አንድ PDF ፋይል፣ ከ«መዋቅር አስመጣ» የወረደ የኤክሴል ቅጽ።', 'A PNG logo under 2 MB, an image over 2 MB, a PDF file, and the Excel template downloaded from «Import Structure».')],
    [t('የይፋዊ ዝርዝር', 'Official list'), t('የሚመዘገቡት ተቋማት ይፋዊ ዝርዝርና የእያንዳንዱ ተቋም ይፋዊ አደረጃጀት (ስሞች፣ ዓይነቶች፣ የበላይ፣ ሕጋዊ ማጣቀሻ፣ የሥራ ክፍሎች)።', 'The official list of institutions to register and each institution\'s official structure (names, types, parent, legal reference, units).')],
    [t('አሳሽ', 'Browser'), t('የቅርብ ጊዜ Chrome ወይም Edge።', 'A recent Chrome or Edge.')],
  ]),
  h2(t('2.1 በሙከራ ሁኔታዎቹ የሚጠቀሱ መዝገቦች', '2.1 Records the test cases refer to')),
  ...paras(t(
    'የሚመዘገቡት ተቋማት አስቀድመው አይታወቁም። የሙከራ ሁኔታዎቹ በሚከተሉት መለያዎች ይጠቅሷቸዋል፤ ሞካሪው በምዝገባው ወቅት ተገቢውን ተቋም መርጦ ስሙን በ«ማስታወሻ» ዓምድ ይጽፋል።',
    'The institutions to be registered are not known in advance. The test cases refer to them by the labels below; during registration the tester picks a suitable institution and writes its name in the «Notes» column.',
  )),
  table([3000, 7400, 4998], [
    [t('መለያ', 'Label'), t('ማለት', 'Meaning'), t('ከየት ይመረጣል', 'Where it comes from')],
    [t('«ተቋም ሀ»', '«Institution A»'), t('በምዝገባው ወቅት ቀድሞ የተመዘገበ ማንኛውም ተቋም', 'Any institution already registered during the registration'), t('ከይፋዊው ዝርዝር', 'The official list')],
    [t('የበላይ ተቋም', 'Parent organization'), t('ተጠሪ ተቋማት የሚመዘገቡበት የተመዘገበ ተቋም (ለምሳሌ ክፍለ ከተማ ወይም ቢሮ)', 'A registered institution that child institutions are registered under (for example a sub-city or bureau)'), t('ከይፋዊው መዋቅር', 'The official structure')],
    [t('«ክፍል ሀ»', '«Unit A»'), t('በአንድ ተቋም ውስጥ የተመዘገበ የሥራ ክፍል', 'A unit registered inside an institution'), t('ከተቋሙ ይፋዊ አደረጃጀት', 'The institution\'s official structure')],
    [t('«ጊዜያዊ መዝገብ»', '«Temporary record»'), t('ለመሰረዝ፣ ለማህደር ወይም የተሳሳተ/የተደገመ መረጃ ለመሞከር ብቻ የሚፈጠር ተቋም፣ ዓይነት ወይም ክፍል። ለመለየት እንዲያመች ስሙን በ«ጊዜያዊ» ይጀምሩ፤ ሙከራው ሲያልቅ ይወገዳል።', 'An organization, type or unit created only to test deletion, archiving or wrong/duplicate data. Start its name with "Temporary" so it is easy to spot; it is removed when testing ends.'), t('በሞካሪው ይፈጠራል', 'Created by the tester')],
  ]),
  h1(t('3. የሙከራ ሁኔታዎች', '3. Test cases'), true),
];

// ── Test case tables ─────────────────────────────────────────────────────
const W = [1150, 2050, 2250, 4150, 3700, 900, 1198];
const caseBlocks = sections.flatMap((s, idx) => [
  h2(s.title, idx > 0),
  ...paras(s.intro, { italics: true, color: '4A5B70' }),
  table(W, [
    [t('መለያ', 'ID'), t('የሙከራ ርዕስ', 'Title'), t('ቅድመ ሁኔታ', 'Precondition'), t('የሙከራ ደረጃዎች', 'Steps'), t('የሚጠበቅ ውጤት', 'Expected result'), t('ውጤት', 'Result'), t('ማስታወሻ', 'Notes')],
    ...s.cases.map((c) => [c.id, c.title, c.pre, c.steps, c.expected, '', '']),
  ]),
]);

// ── Result summary, defect log, sign-off ─────────────────────────────────
const closing = [
  h1(t('4. የሙከራ አፈጻጸም ማጠቃለያ ቅጽ', '4. Test execution summary'), true),
  ...paras(t('ሙከራው ሲጠናቀቅ ለእያንዳንዱ ክፍል የተገኘውን ቁጥር ይሙሉ።', 'When testing is complete, fill in the counts for each section.')),
  table([5398, 2000, 2000, 2000, 2000, 2000], [
    [t('ክፍል', 'Section'), t('ጠቅላላ', 'Total'), t('አለፈ', 'Pass'), t('ወደቀ', 'Fail'), t('ታግዷል', 'Blocked'), t('አልተፈጸመም', 'Not run')],
    ...sections.map((s) => [s.title, String(s.cases.length), '', '', '', '']),
    [t('ድምር', 'Total'), String(total), '', '', '', ''],
  ]),
  h1(t('5. የጉድለት መመዝገቢያ ቅጽ', '5. Defect log')),
  table([1300, 1500, 4200, 3000, 1500, 1700, 2198], [
    [t('ተ.ቁ.', 'No.'), t('የሙከራ መለያ', 'Test ID'), t('የጉድለቱ መግለጫ (ምን ተደረገ፣ ምን ተገኘ)', 'Description (what was done, what happened)'), t('የሚጠበቀው', 'Expected'),
      t('ክብደት (ከፍተኛ/መካከለኛ/ዝቅተኛ)', 'Severity (high/medium/low)'), t('ያገኘው / ቀን', 'Found by / date'), t('ሁኔታ (ክፍት/ተስተካክሏል/ተረጋግጧል)', 'Status (open/fixed/verified)')],
    ...Array.from({ length: 10 }, (_, i) => [String(i + 1), '', '', '', '', '', '']),
  ]),
  h1(t('6. ማረጋገጫና ፊርማ', '6. Sign-off')),
  table([3500, 4300, 3800, 3798], [
    [t('ሚና', 'Role'), t('ስም', 'Name'), t('ፊርማ', 'Signature'), t('ቀን', 'Date')],
    [t('ሞካሪ', 'Tester'), '', '', ''],
    [t('የሥርዓቱ አስተዳዳሪ', 'System administrator'), '', '', ''],
    [t('የተቋማት ምዝገባ ኃላፊ', 'Head of institution registration'), '', '', ''],
    [t('ያጸደቀው', 'Approved by'), '', '', ''],
  ]),
];

const footerWords = { am: ['ገጽ ', ' ከ '], en: ['Page ', ' of '], bi: ['ገጽ / Page ', ' / '] }[MODE];
const doc = new Document({
  creator: 'Agelgay',
  title: flat(S.docTitle),
  styles: { default: { document: { run: { font: MODE === 'en' ? EN_FONT : AM_FONT, size: 22 } } } },
  features: { updateFields: true },
  sections: [{
    properties: {
      page: {
        size: { width: 11906, height: 16838, orientation: PageOrientation.LANDSCAPE },
        margin: { top: 720, bottom: 720, left: 720, right: 720 },
      },
    },
    headers: { default: new Header({ children: [new Paragraph({ alignment: AlignmentType.RIGHT, children: [
      new TextRun({ text: `${flat(S.docTitle)}  •  ${MODE === 'am' ? 'ስሪት' : MODE === 'en' ? 'Version' : 'ስሪት / Version'} ${VERSION}`, font: MODE === 'en' ? EN_FONT : AM_FONT, size: 16, color: '6B7A8C' }),
    ] })] }) },
    footers: { default: new Footer({ children: [new Paragraph({ alignment: AlignmentType.CENTER, children: [
      new TextRun({ font: MODE === 'en' ? EN_FONT : AM_FONT, size: 16, color: '6B7A8C', children: [footerWords[0], PageNumber.CURRENT, footerWords[1], PageNumber.TOTAL_PAGES] }),
    ] })] }) },
    children: [...cover, ...control, ...intro, ...caseBlocks, ...closing],
  }],
});

Packer.toBuffer(doc).then((buf) => { fs.writeFileSync(process.argv[3], buf); console.log('wrote', process.argv[3], MODE, total, 'cases'); });
