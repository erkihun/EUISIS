// Code-rule test cases (Amharic and English). Two sections: rules checked
// before any record is registered (CR), and rules that need issued codes
// (CRL). Counter changes use a temporary rule tied to a temporary type, so
// testing never moves the numbering of real institutions.
// Case: [id, title, precondition, steps[], expected[]]
module.exports = {
  am: {
    CR: {
      id: 'CR', title: 'የኮድ ደንቦች',
      intro: 'ማንኛውም መዝገብ (የተቋም ዓይነት፣ ተቋም፣ የሥራ ክፍል፣ የሥራ መደብ) ኮዱን የሚያገኘው ከኮድ ደንብ ነው፤ ስለዚህ ደንቦቹ ከሁሉም ምዝገባ በፊት ይረጋገጣሉ።',
      cases: [
        ['TC-CR-01', 'የሚያስፈልጉት የኮድ ደንቦች በሙሉ ንቁ መሆናቸው', 'በአስተዳዳሪ መለያ መግባት', [
          '«የኮድ ደንቦች» ገጽን ይክፈቱ፤ በ«የአካል ዓይነት» ያጣሩ።',
          'ለተቋም፣ ለተቋም ዓይነት፣ ለሥራ ክፍል፣ ለሥራ ክፍል ዓይነት፣ ለሥራ መደብና ለሠራተኛ ቁጥር ደንብ መኖሩን ይመልከቱ።',
          'ለእያንዳንዱ «ቅርጸት»፣ «ቀጣይ ቁጥር» እና «ዳግም መጀመሪያ ድግግሞሽ» ይመልከቱ።',
        ], [
          'ስድስቱም ደንቦች ንቁ ናቸው።',
          'ቅርጸቱ በተቋሙ የተወሰነውን የኮድ አወጣጥ ይከተላል (የተስማማበትን ቅርጸት በ«ማስታወሻ» ይጻፉ)።',
        ]],
        ['TC-CR-02', 'ቅርጸት ተከታታይ ቁጥር መያዝ አለበት', 'code-rules ፈቃድ ያለው መለያ', [
          '«የኮድ ደንብ ይፍጠሩ» ይክፈቱ፤ ቅርጸቱን «{PREFIX}-{YEAR}» (ያለ {SEQUENCE}) አድርገው ያስቀምጡ።',
          'ቅርጸቱን «{PREFIX}-{ABC}-{SEQUENCE}» (የማይታወቅ ምልክት) አድርገው ያስቀምጡ።',
        ], [
          'የመጀመሪያው ቅርጸቱ ተከታታይ ቁጥር መያዝ እንዳለበት በሚገልጽ ስህተት ይመለሳል።',
          'ሁለተኛው የማይታወቅ ምልክት እንዳለ በሚገልጽ ስህተት ይመለሳል።',
        ]],
        ['TC-CR-03', 'ለአንድ ዓይነት ሁለት ንቁ ደንቦች', 'የተቋም ኮድ ደንብ ንቁ ነው', [
          'ለተቋም፣ ያለ ወሰን፣ ሌላ ንቁ ደንብ ለመፍጠር ይሞክሩ።',
        ], [
          'ለዚህ አካልና ወሰን ንቁ ደንብ አስቀድሞ እንዳለ የሚገልጽ ስህተት ይታያል።',
        ]],
        ['TC-CR-04', 'ቅድመ እይታ ቁጥር አይበላም', 'የተቋም ኮድ ደንብ ንቁ ነው', [
          '«አዲስ ተቋም» ቅጽን ይክፈቱ፤ የሚታየውን ቀጣይ ኮድ ይጻፉ፤ ሳያስቀምጡ ይዝጉ።',
          'ቅጹን እንደገና ይክፈቱ።',
          'የደንቡን «ቀጣይ ቁጥር» ይመልከቱ።',
        ], [
          'ሁለቱም ጊዜ ተመሳሳይ ቀጣይ ኮድ ይታያል።',
          'የደንቡ ቀጣይ ቁጥር አልተቀየረም።',
        ]],
        ['TC-CR-05', 'በቅጾቹ ላይ ኮድ በእጅ አይገባም', 'በአስተዳዳሪ መለያ መግባት', [
          'የ«አዲስ ተቋም»፣ የተቋም ዓይነት፣ የሥራ ክፍል፣ የሥራ ክፍል ዓይነትና የሥራ መደብ ቅጾችን ይክፈቱ።',
          'የኮድ መስኩን ለመጻፍ ይሞክሩ።',
        ], [
          'በሁሉም ቅጾች የኮድ መስኩ ቅድመ እይታ ብቻ ነው፤ መጻፍ አይቻልም፤ ኮዱ ሲቀመጥ በደንቡ ይፈጠራል።',
        ]],
      ],
    },
    CRL: {
      id: 'CRL', title: 'ኮዶች ከተሰጡ በኋላ የኮድ ደንቦች',
      intro: 'ኮድ ከተሰጠ በኋላ ብቻ የሚሞከሩ ደንቦች። ትክክለኛውን የኮድ ቁጥር እንዳይነኩ ለ«ጊዜያዊ ዓይነት» ብቻ የሚሠራ «ጊዜያዊ የኮድ ደንብ» ይጠቀማሉ።',
      cases: [
        ['TC-CR-06', 'ለአንድ ዓይነት የተለየ ደንብ ቅድሚያ ማግኘቱ', 'ጊዜያዊ የተቋም ዓይነት ተፈጥሯል', [
          'ለተቋም «ጊዜያዊ የኮድ ደንብ» ይፍጠሩ፦ «የወሰን ዓይነት» የተቋም ዓይነት፣ ወሰኑ ጊዜያዊው ዓይነት፣ ቅድመ ኮድ ለምሳሌ TMP።',
          'በጊዜያዊው ዓይነት አንድ «ጊዜያዊ መዝገብ» ይመዝግቡ።',
          'የ«ተቋም ሀ»ን ኮድ ያስታውሱ (አጠቃላዩን ደንብ ይከተላል)።',
        ], [
          'ጊዜያዊ መዝገቡ በTMP የሚጀምር ኮድ ያገኛል፤ ሌሎች ተቋማት አጠቃላዩን ደንብ መከተላቸውን ይቀጥላሉ።',
        ]],
        ['TC-CR-07', 'የደንብ ስም ብቻ ሲቀየር ቁጥሩ አይቀየርም', 'TC-CR-06 አልፏል', [
          'የጊዜያዊውን ደንብ ስም ብቻ ቀይረው ያስቀምጡ።',
          'የደንቡን «ቀጣይ ቁጥር» ይመልከቱ፤ ሌላ «ጊዜያዊ መዝገብ» ይመዝግቡ።',
        ], [
          'ቀጣዩ ቁጥር አልተቀየረም፤ አዲሱ መዝገብ ከቀደመው ቀጥሎ ያለውን ኮድ ያገኛል (ዝላይም ዳግም መጀመርም የለም)።',
        ]],
        ['TC-CR-08', 'ቀጣይ ቁጥርን ማሳደግ', 'TC-CR-07 አልፏል', [
          'የጊዜያዊውን ደንብ «ቀጣይ ቁጥር» 100 አድርገው ያስቀምጡ።',
          'ሌላ «ጊዜያዊ መዝገብ» ይመዝግቡ።',
        ], [
          'አዲሱ መዝገብ በ0100 የሚያልቅ ኮድ ያገኛል።',
        ]],
        ['TC-CR-09', 'ቀጣይ ቁጥርን ከተሰጡት ኮዶች በታች ማድረግ', 'TC-CR-08 አልፏል', [
          'የጊዜያዊውን ደንብ «ቀጣይ ቁጥር» 2 አድርገው ያስቀምጡ።',
        ], [
          '«ቀጣዩ ቁጥር ከ… በታች ሊሆን አይችልም፦ እስከ … ያሉ ኮዶች ቀድመው ተሰጥተዋል።» የሚል ስህተት ይታያል፤ ለውጡ አይቀመጥም።',
        ]],
        ['TC-CR-10', 'ኮድ የሰጠ ደንብን የመዝገብ ዓይነት መቀየር', 'TC-CR-06 አልፏል', [
          'የጊዜያዊውን ደንብ «የአካል ዓይነት» ወደ ሌላ ዓይነት (ለምሳሌ የሥራ ክፍል) ቀይረው ያስቀምጡ።',
        ], [
          '«ይህ ደንብ ቀድሞ ኮዶችን ስለሰጠ የሚቆጥረው የመዝገብ ዓይነት ሊቀየር አይችልም።» የሚል ስህተት ይታያል።',
        ]],
        ['TC-CR-11', 'ተከታታይ ቁጥርን ዳግም መጀመር', 'TC-CR-08 አልፏል', [
          'በጊዜያዊው ደንብ ገጽ ላይ የተከታታይ ቁጥሮቹን ዝርዝር ከፍተው «ተከታታይ ቁጥር ዳግም ይጀምሩ» ይጫኑ።',
          'ሌላ «ጊዜያዊ መዝገብ» ይመዝግቡ።',
        ], [
          'ዳግም መጀመሩ በኦዲት ምዝግብ ይመዘገባል።',
          'አዲሱ መዝገብ ተይዞ የማይገኝ የመጀመሪያውን ነፃ ኮድ ያገኛል፤ ቀድሞ የተሰጠ ኮድ አይደገምም።',
        ]],
        ['TC-CR-12', 'በኤክሴል ፋይል የተጻፈ የተያዘ ኮድ', 'የነባር የሥራ መደብ ኮድ ወይም የሠራተኛ ቁጥር የያዘ የመዋቅር ፋይል', [
          'ፋይሉን በ«መዋቅር አስመጣ» ይጫኑ።',
        ], [
          'ኮዱ ቀድሞ መያዙን የሚገልጽ ስህተት በቅድመ እይታው ይታያል፤ ማረጋገጥ አይቻልም፤ ምንም አይፈጠርም።',
        ]],
        ['TC-CR-13', 'ደንብን መሰረዝና መመለስ', 'TC-CR-06 አልፏል', [
          'ጊዜያዊውን ደንብ ይሰርዙ።',
          'በጊዜያዊው ዓይነት ሌላ «ጊዜያዊ መዝገብ» ይመዝግቡ።',
          'ደንቡን ይመልሱ።',
        ], [
          'ደንቡ ሲሰረዝ መዝገቡ አጠቃላዩን የተቋም ኮድ ደንብ ይከተላል።',
          'ደንቡ ይመለሳል፤ ቀጣይ ጊዜያዊ መዝገቦች እንደገና TMP ኮድ ያገኛሉ።',
        ]],
        ['TC-CR-14', 'የኮድ አሰጣጥ ታሪክ', 'ቢያንስ አንድ ኮድ ተሰጥቷል', [
          'የደንቡን የተከታታይ ቁጥሮች ዝርዝር ይክፈቱ።',
          '«የኦዲት ምዝግቦች» ውስጥ የኮድ መፈጠር ክስተቶችን ይፈልጉ።',
        ], [
          'የመጨረሻው የተሰጠ ኮድና ቀጣዩ ቁጥር ይታያሉ።',
          'እያንዳንዱ የተሰጠ ኮድ ከደንቡና ከሰጠው መለያ ጋር ተመዝግቧል።',
        ]],
      ],
    },
    dq: ['TC-DQ-09', 'የተሰጡ ኮዶች', 'ትክክለኛው ምዝገባ ተጠናቋል', [
      'የተቋማትን፣ የሥራ ክፍሎችንና የሥራ መደቦችን ዝርዝር ከፍተው ኮዶቹን ይመልከቱ።',
      'ጊዜያዊውን የኮድ ደንብና ጊዜያዊውን ዓይነት ያስወግዱ (TC-DQ-06)።',
    ], [
      'እያንዳንዱ ኮድ የደንቡን ቅርጸት ይከተላል፤ የተደገመ ኮድ የለም።',
      'ጊዜያዊ ደንብ ወይም በTMP የሚጀምር ኮድ ያለው መዝገብ አይቀርም።',
    ]],
  },
  en: {
    CR: {
      title: 'Code rules',
      intro: 'Every record (organization type, organization, unit, position) gets its code from a code rule, so the rules are checked before any registration.',
      cases: {
        'TC-CR-01': ['All needed code rules are active', 'Signed in as an administrator', [
          'Open «Code Rules»; filter by «Entity Type».',
          'Confirm a rule exists for organizations, organization types, organization units, unit types, positions and employee numbers.',
          'For each, check «Format», «Next Number» and «Reset Frequency».',
        ], [
          'All six rules are active.',
          'The formats follow the numbering the institution decided (write the agreed format in «Notes»).',
        ]],
        'TC-CR-02': ['A format must contain a sequence', 'Account with code-rules permission', [
          'Open «Create Code Rule»; set the format to "{PREFIX}-{YEAR}" (no {SEQUENCE}) and save.',
          'Set the format to "{PREFIX}-{ABC}-{SEQUENCE}" (an unknown token) and save.',
        ], [
          'The first is refused: the format must contain a sequence.',
          'The second is refused: the format has an unknown token.',
        ]],
        'TC-CR-03': ['Two active rules for one record type', 'The organization code rule is active', [
          'Try to create another active rule for organizations, with no scope.',
        ], [
          'An error says an active rule already exists for this entity and scope.',
        ]],
        'TC-CR-04': ['Preview does not use up a number', 'The organization code rule is active', [
          'Open «New Organization»; note the next code shown; close without saving.',
          'Open the form again.',
          'Look at the rule\'s «Next Number».',
        ], [
          'The same next code is shown both times.',
          'The rule\'s next number has not changed.',
        ]],
        'TC-CR-05': ['Codes cannot be typed on the forms', 'Signed in as an administrator', [
          'Open the forms for a new organization, organization type, unit, unit type and position.',
          'Try to type in the code field.',
        ], [
          'On every form the code field is a preview only and cannot be typed in; the code is generated by the rule when the record is saved.',
        ]],
      },
    },
    CRL: {
      title: 'Code rules once codes have been issued',
      intro: 'Rules that can be tested only after codes are issued. They use a «temporary code rule» that applies only to a «temporary type», so the numbering of real records is never touched.',
      cases: {
        'TC-CR-06': ['A rule for one type takes precedence', 'A temporary organization type has been created', [
          'Create a «temporary code rule» for organizations: «Scope Type» organization type, scope the temporary type, prefix for example TMP.',
          'Register a «temporary record» of the temporary type.',
          'Note «Institution A»\'s code (it follows the general rule).',
        ], [
          'The temporary record gets a code starting with TMP; other organizations keep following the general rule.',
        ]],
        'TC-CR-07': ['Renaming a rule does not change its numbering', 'TC-CR-06 passed', [
          'Change only the temporary rule\'s name; save.',
          'Look at the rule\'s «Next Number»; register another «temporary record».',
        ], [
          'The next number is unchanged; the new record gets the code right after the previous one (no jump, no restart).',
        ]],
        'TC-CR-08': ['Raise the next number', 'TC-CR-07 passed', [
          'Set the temporary rule\'s «Next Number» to 100; save.',
          'Register another «temporary record».',
        ], [
          'The new record gets a code ending in 0100.',
        ]],
        'TC-CR-09': ['Lower the next number below issued codes', 'TC-CR-08 passed', [
          'Set the temporary rule\'s «Next Number» to 2; save.',
        ], [
          'The error "The next number cannot go below …: codes up to … have already been issued." is shown; the change is not saved.',
        ]],
        'TC-CR-10': ['Change the record type of a rule that issued codes', 'TC-CR-06 passed', [
          'Change the temporary rule\'s «Entity Type» to another type (for example organization unit); save.',
        ], [
          'The error "This rule has already issued codes, so the record type it numbers cannot change." is shown.',
        ]],
        'TC-CR-11': ['Reset a sequence', 'TC-CR-08 passed', [
          'On the temporary rule\'s page, open its sequences and click «Reset Sequence».',
          'Register another «temporary record».',
        ], [
          'The reset is recorded in the audit log.',
          'The new record gets the first free code; no code already issued is repeated.',
        ]],
        'TC-CR-12': ['A taken code written in an Excel file', 'A structure file with an existing position code or employee number', [
          'Upload the file in «Import Structure».',
        ], [
          'The preview shows an error that the code is already taken; confirmation is not possible; nothing is created.',
        ]],
        'TC-CR-13': ['Delete and restore a rule', 'TC-CR-06 passed', [
          'Delete the temporary rule.',
          'Register another «temporary record» of the temporary type.',
          'Restore the rule.',
        ], [
          'While the rule is deleted, the record follows the general organization code rule.',
          'The rule is restored; later temporary records get TMP codes again.',
        ]],
        'TC-CR-14': ['Code issuing history', 'At least one code has been issued', [
          'Open the rule\'s list of sequences.',
          'In «Audit Logs», look for code-generated events.',
        ], [
          'The last code issued and the next number are shown.',
          'Each issued code is recorded with its rule and the account that issued it.',
        ]],
      },
    },
    dq: ['Issued codes', 'The real registration is complete', [
      'Open the lists of organizations, units and positions and look at their codes.',
      'Remove the temporary code rule and the temporary type (TC-DQ-06).',
    ], [
      'Every code follows its rule\'s format; no code is duplicated.',
      'No temporary rule, and no record with a code starting with TMP, remains.',
    ]],
  },
};
