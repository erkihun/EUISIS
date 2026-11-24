// Builds the trainees' acceptance-test document for the pilot training of
// trainers, in the exact format of the institution registration test cases
// (reports/Organization_Registration_Test_Cases_Amharic_English_v1.4.docx).
//
// It does not change that document or its generator. It loads the generator
// source, keeps only the sections a trainee runs on their own institution
// (14, 15, 18, 19) with the numbering of the full document, adds the two
// sections of uat_import_cases.cjs (positions and employees from a file, the
// imports a trainee can use) and rewrites the cover, the purpose and the
// prerequisites for the training. Section 17 (structure import) is left out:
// only system-level roles can see that page.
//
// Run:  node reports/pilot-training/build_uat_docx.cjs <folder with node_modules/docx>
const fs = require('fs');
const path = require('path');
const Module = require('module');

const generatorDir = path.resolve(__dirname, '..', 'organization-registration-test-cases');
const generator = path.join(generatorDir, 'build.cjs');
const out = path.join(__dirname, 'Agelgay_ToT_UAT_Test_Cases_Amharic_English.docx');
const docxHome = process.argv[2];

let src = fs.readFileSync(generator, 'utf8');
const rep = (from, to) => {
  if (src.split(from).length !== 2) throw new Error(`expected exactly one match for: ${from.slice(0, 60)}`);
  src = src.replace(from, () => to);
};

// Sections kept, by their number in the full document.
rep('const total = sections.reduce(',
  'const KEEP = [14, 15, 18, 19];\n'
  + 'sections.splice(0, sections.length, ...sections.filter((_, i) => KEEP.includes(i + 1)));\n'
  // The two imports a trainee can use, in execution order after the units.
  + `sections.splice(2, 0, ...require(${JSON.stringify(path.join(__dirname, 'uat_import_cases.cjs'))})(t));\n`
  // Section 18 named the structure import as the source of its records.
  + "sections.forEach((s) => s.cases.forEach((c) => { c.pre = { am: c.pre.am.replace('በTC-17-06 የገባ', 'በተጨማሪ ክፍል ሀ እና ለ የተመዘገበ'), en: c.pre.en.replace('imported in TC-17-06', 'registered in Additional sections A and B') }; }));\n"
  + 'const total = sections.reduce(');

rep("const VERSION = '1.4';", "const VERSION = '1.0';");
rep("title: t('የተቋማት ምዝገባ', 'Institution Registration'),",
  "title: t('የአሠልጣኞች ሥልጠና', 'Training of Trainers'),");
rep("subtitle: t('የሙከራ ሁኔታዎች', 'Test Cases'),",
  "subtitle: t('የተቀባይነት ማረጋገጫ የሙከራ ሁኔታዎች', 'User Acceptance Test Cases'),");
rep("purposeLine: t('ተቋማት ወደ ምርት (production) ሥርዓቱ ከመመዝገባቸው በፊትና በምዝገባው ወቅት የሚደረግ ሙከራ', 'Testing before and during the registration of institutions in the production system'),",
  "purposeLine: t('የሙከራ ትግበራ ተቋማት ሠልጣኞች በራሳቸው ተቋም ላይ የሚያከናውኑት ሙከራ', 'Testing carried out by the trainees of the pilot institutions on their own institution'),");
rep("date: t('ጥቅምት 22 ቀን 2019 ዓ.ም.', '1 November 2026'),",
  "date: t('ኅዳር 13 ቀን 2019 ዓ.ም.', '22 November 2026'),");
rep("docTitle: t('የተቋማት ምዝገባ — የሙከራ ሁኔታዎች', 'Institution Registration — Test Cases'),",
  "docTitle: t('የአሠልጣኞች ሥልጠና — የተቀባይነት ማረጋገጫ የሙከራ ሁኔታዎች', 'Training of Trainers — User Acceptance Test Cases'),");
rep('`ስሪት ${VERSION}  •  ጥቅምት 22 ቀን 2019 ዓ.ም. (01 November 2026)`, `Version ${VERSION}  •  1 November 2026 (ጥቅምት 22 ቀን 2019 ዓ.ም.)`',
  '`ስሪት ${VERSION}  •  ኅዳር 13 ቀን 2019 ዓ.ም. (22 November 2026)`, `Version ${VERSION}  •  22 November 2026 (ኅዳር 13 ቀን 2019 ዓ.ም.)`');

// Scope line of the document control table.
rep("'የተቋም ዓይነቶች፣ የተዋረድ ስሪቶች፣ የተቋማት ምዝገባና የተዋረድ ምደባ፣ የሥራ ክፍል ዓይነቶች፣ የሥራ ክፍሎች፣ የተጠሪነት መስመሮች፣ ማስተካከያ፣ ማቦዘንና ማህደር፣ ፈቃድና ወሰን፣ በኤክሴል መዋቅር ማስገባት'",
  "'የሥራ ክፍሎች፣ የተጠሪነት መስመሮች፣ የሥራ መደቦችን ከፋይል መመዝገብ፣ ሠራተኞችን ከፋይል መመዝገብ፣ የሥራ መደብና ሠራተኛ ያላቸው የሥራ ክፍሎች፣ ከምዝገባ በኋላ የሚደረግ የመረጃ ማረጋገጫ'");
rep("'Organization types, hierarchy versions, organization registration and hierarchy placement, unit types, organization units, reporting lines, editing, deactivation and archiving, permissions and scope, structure import from Excel'",
  "'Organization units, reporting lines, registering positions from a file, registering employees from a file, units with positions and employees, data check after registration'");

// Purpose.
rep('ይህ ሰነድ ምዝገባው ከመጀመሩ በፊትና በምዝገባው ወቅት ሥርዓቱ በትክክል መሥራቱን ለማረጋገጥ የሚደረጉ ${total} የሙከራ ሁኔታዎችን ይዟል።',
  'ይህ ሰነድ በአሠልጣኞች ሥልጠና ወቅት ሠልጣኞች በራሳቸው ተቋም ላይ የሚያከናውኗቸውን ${total} የሙከራ ሁኔታዎች ይዟል። የሙከራ ሁኔታዎቹ ከ«የተቋማት ምዝገባ — የሙከራ ሁኔታዎች» ሰነድ ስሪት 1.4 የተወሰዱ ናቸው፤ የክፍሎቹና የሙከራ ሁኔታዎቹ ቁጥር ከዚያ ሰነድ ጋር አንድ ነው። ተጨማሪ ክፍል ሀ እና ተጨማሪ ክፍል ለ ለዚህ ሰነድ አዲስ የተዘጋጁ ናቸው፤ ሥርዓቱ ራሱ በሚያዘጋጃቸው ፋይሎች የሥራ መደቦችንና ሠራተኞችን መመዝገብን ይፈትሻሉ። በዚህ ሰነድ ያልተካተቱት ክፍሎች ከሥልጠናው በፊት በአሠልጣኞች ቡድን ይከናወናሉ።');
rep('This document holds ${total} test cases that confirm the system works correctly before and during registration.',
  'This document holds the ${total} test cases that trainees run on their own institution during the training of trainers. The cases are taken from «Institution Registration — Test Cases» version 1.4, and the section and case numbers are the same as in that document. Additional sections A and B are new to this document; they test registering positions and employees with the files the system itself generates. The sections left out here are run by the training team before the training.');

// Order of testing.
rep('ክፍል 2–${last - 1} የሚከናወኑት በምዝገባው ወቅት በሚመዘገቡት ተቋማት ነው። ተቋማቱ አስቀድመው ስለማይታወቁ ሰነዱ',
  'ሙከራዎቹ የሚከናወኑት በሠልጣኙ የራሱ ተቋም ላይ ነው። ሰነዱ ለሁሉም ተቋማት አንድ ስለሆነ መዝገቦቹን');
rep('Sections 2–${last - 1} are run on the institutions being registered. Since those are not known in advance, the document refers to them',
  'The tests are run on the trainee\'s own institution. Since the document is the same for every institution, it refers to the records');
rep('ክፍል ${last} ትክክለኛው ምዝገባ ከተጠናቀቀ በኋላ፣', 'የመጨረሻው ክፍል (ክፍል 19) ትክክለኛው ምዝገባ ከተጠናቀቀ በኋላ፣');
rep('Section ${last} is run after the real registration is complete,', 'The last section (Section 19) is run after the real registration is complete,');

// Prerequisites.
rep("`(ሀ) Super Admin ወይም System Admin፣ (ሐ) ተቋማትን ማየት ብቻ የሚችል መለያ። መለያ (ለ) — ለአንድ ተቋም የተመደበ Organizational Admin — ተቋሙ ከተመዘገበ በኋላ በክፍል ${secOf('TC-SEC-02')} ይፈጠራል። የኤክሴል ማስገቢያ ለመሞከር organizations.import ፈቃድ ያስፈልጋል።`",
  "'ለተቋሙ የአይሲቲ ባለሙያ የተሰጠ የተቋም አስተዳዳሪ መለያ እና ለሰው ሀብት ባለሙያው የተሰጠ የሰው ሀብት ኦፊሰር መለያ። የሥርዓት አስተዳዳሪ መብት የሚጠይቁ የሙከራ ሁኔታዎችን አሠልጣኙ በራሱ መለያ ከሠልጣኞቹ ጋር ሆኖ ያከናውናል።'");
rep("`(a) Super Admin or System Admin, (c) an account that can only view organizations. Account (b) — an Organizational Admin assigned to one institution — is created in Section ${secOf('TC-SEC-02')}, once that institution is registered. The Excel import needs the organizations.import permission.`",
  "'The institution administrator account given to the institution\\'s ICT officer and the human resource officer account given to its HR officer. The trainer runs the test cases that need system administrator rights from the trainer\\'s own account, together with the trainees.'");
rep("t(`ተቋሙ የወሰነው የኮድ አወጣጥ (የእያንዳንዱ መዝገብ ቅርጸት)። ደንቦቹ በክፍል ${secOf('TC-CR-01')} ይረጋገጣሉ።`, `The numbering the institution decided (the format for each record type). The rules themselves are checked in Section ${secOf('TC-CR-01')}.`)",
  "t('ኮዶች በሥርዓቱ የኮድ ደንቦች መሠረት በራሳቸው ይሰጣሉ፤ ሠልጣኙ ኮድ በእጁ አይጽፍም። ደንቦቹ ከሥልጠናው በፊት በአሠልጣኞች ቡድን ተረጋግጠዋል።', 'Codes are issued by the system from its code rules; the trainee does not type codes. The rules were checked by the training team before the training.')");
rep("[t('በሙከራው የሚፈጠሩ', 'Created during testing'), t('የተቋም ዓይነቶች፣ የተዋረድ ስሪቶች፣ የሥራ ክፍል ዓይነቶችና የሥራ ክፍሎች አስቀድመው አይጠበቁም፤ በቅደም ተከተሉ መሠረት በየክፍላቸው ይፈጠራሉ።', 'Organization types, hierarchy versions, unit types and units are not expected in advance; they are created in their own sections, in order.')]",
  "[t('አስቀድመው የተዘጋጁና በሙከራው የሚፈጠሩ', 'Prepared in advance and created during testing'), t('ተቋሙ፣ የተቋም ዓይነቶች፣ የተዋረድ ስሪቱ እና የሥራ ክፍል ዓይነቶች ከሥልጠናው በፊት በአሠልጣኞች ቡድን ተዘጋጅተዋል። የሥራ ክፍሎች፣ የተጠሪነት መስመሮች፣ የሥራ መደቦች እና ሠራተኞች በዚህ ሙከራ ይፈጠራሉ።', 'The institution, the organization types, the hierarchy version and the unit types were prepared by the training team before the training. Units, reporting lines, positions and employees are created during this testing.')]");
rep("ከ«መዋቅር አስመጣ» የወረደ የኤክሴል ቅጽ።', 'A PNG logo under 2 MB, an image over 2 MB, a PDF file, and the Excel template downloaded from «Import Structure».')",
  "ሥርዓቱ ራሱ የሚያዘጋጃቸው የሥራ መደብ እና የሠራተኞች ማስገቢያ ፋይሎች።', 'A PNG logo under 2 MB, an image over 2 MB, a PDF file, and the positions and employees import files the system itself generates.')");
rep("t('የሚመዘገቡት ተቋማት ይፋዊ ዝርዝርና የእያንዳንዱ ተቋም ይፋዊ አደረጃጀት (ስሞች፣ ዓይነቶች፣ የበላይ፣ ሕጋዊ ማጣቀሻ፣ የሥራ ክፍሎች)።', 'The official list of institutions to register and each institution\\'s official structure (names, types, parent, legal reference, units).')",
  "t('የተቋሙ ይፋዊ የአደረጃጀት ሠንጠረዥ፣ የተፈቀደው የሥራ መደብ ዝርዝር እና የሠራተኞች ዝርዝር።', 'The institution\\'s official structure chart, its approved list of positions and its list of employees.')");

// Run the patched generator as if it were the original file, writing elsewhere.
process.argv[2] = 'bi';
process.argv[3] = out;
const m = new Module(generator, module);
m.filename = generator;
m.paths = [...Module._nodeModulePaths(generatorDir), path.join(docxHome, 'node_modules')];
m._compile(src, generator);
