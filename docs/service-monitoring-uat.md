# Service Delivery Monitoring — UAT script / የተጠቃሚ ተቀባይነት ሙከራ

Run on a UAT database seeded with `php artisan db:seed --class=DemoDataSeeder`, which creates FY 2019
E.C., DEMO Arada Sub-city with woredas 01 and 02 and the demo Civic Services Bureau as a center
institution. Give the testers these roles and scopes (Users page):

| Tester | Role | Scope |
| --- | --- | --- |
| Woreda head | Service Monitoring Head | DEMO Arada Woreda 01 (self) |
| Woreda reporter | Service Monitoring Reporter | DEMO Arada Woreda 01 (self) |
| Sub-city reviewer | Service Monitoring Reviewer | DEMO Arada Sub-city and its woredas (self, each) |
| City coordinator | Service Monitoring Coordinator | city-wide |
| Technology verifier | Innovation & Technology Verifier | city-wide |
| Support officer | Support Officer | assigned to Woreda 01 on the Reporting institutions page |

Each step lists what to do and what you should see. Record pass / fail and screenshots.

## 1. Woreda submission / የወረዳ ሪፖርት ማቅረብ

| # | English | አማርኛ |
| --- | --- | --- |
| 1.1 | As the woreda reporter, open *Service Delivery Monitoring → Dashboard*. The current period, its deadline (Ethiopian calendar) and the countdown show. | እንደ ወረዳ ሪፖርት አዘጋጅ *የአገልግሎት አሰጣጥ ክትትልና ድጋፍ → ዳሽቦርድ* ይክፈቱ። የአሁኑ ጊዜ፣ የጊዜ ገደቡ (በኢትዮጵያ ዘመን አቆጣጠር) እና የቀሩት ቀናት ይታያሉ። |
| 1.2 | Choose *Open the report*. The entry screen shows four tabs with the official section titles. | *ሪፖርቱን ክፈት* ይምረጡ። አራት ክፍሎች በይፋዊ ርዕሶቻቸው ይታያሉ። |
| 1.3 | Section I: add a customer row (legal action, office named). Add an employee row; the name appears hidden to you after saving (you are a reporter). | ክፍል አንድ፦ የተገልጋይ ረድፍ (የህግ እርምጃ፣ ጽ/ቤት ተጠቅሶ) ይጨምሩ። የሠራተኛ ረድፍ ይጨምሩ፤ ከተቀመጠ በኋላ ስሙ ለእርስዎ ተደብቆ ይታያል። |
| 1.4 | Try a date outside the quarter: refused with the quarter dates. | ከሩብ ዓመቱ ውጪ ቀን ይሞክሩ፤ ውድቅ ይሆናል። |
| 1.5 | Section II: the employee and chest-badge figures show a system figure. Change one: a reason (10+ characters) is asked. The page shows *Saved* after a moment. | ክፍል ሁለት፦ የሠራተኛና የደረት ባጅ አሃዞች የሲስተም አሃዝ ያሳያሉ። አንዱን ይቀይሩ፤ ምክንያት ይጠየቃል። ከጥቂት ጊዜ በኋላ *ተቀምጧል* ይታያል። |
| 1.6 | Section III: enter the main and sub service totals; mark "የለም" if no digital service went live this year. | ክፍል ሶስት፦ የዋና እና የንዑስ አገልግሎቶችን ብዛት ያስገቡ፤ ዲጂታል አገልግሎት ከሌለ "የለም" ይበሉ። |
| 1.7 | Section IV: enter above/at/below; the sum check turns green only when it equals the total. With anything below standard, reasons and actions are required. | ክፍል አራት፦ በላይ/በስታንዳርድ/በታች ያስገቡ፤ ድምሩ ከጠቅላላው ጋር ሲመሳሰል ብቻ ይጸድቃል። |
| 1.8 | As the reporter, *Attest and submit* is not offered. As the woreda head, it is: the declaration must be ticked; the stamped copy is optional. Submit. | ሪፖርት አዘጋጁ *አረጋግጠህ አቅርብ* አያገኝም። የወረዳው ኃላፊ ያገኛል፤ የማረጋገጫ ቃሉን ምልክት አድርገው ያቅርቡ። |

## 2. Sub-city review and certification / የክ/ከተማ ግምገማና ማረጋገጫ

| # | English | አማርኛ |
| --- | --- | --- |
| 2.1 | As the sub-city reviewer, open *Review queue*: the woreda report is there. Return it with a reason and a line comment on Section II. | እንደ ክ/ከተማ ገምጋሚ *የግምገማ ወረፋ* ይክፈቱ፤ የወረዳው ሪፖርት አለ። በምክንያትና በክፍል ሁለት የመስመር አስተያየት ይመልሱት። |
| 2.2 | The woreda head gets a notice; the entry screen shows the comment on Section II. Correct and resubmit. | የወረዳው ኃላፊ ማሳወቂያ ይደርሰዋል፤ አስተያየቱ በክፍል ሁለት ይታያል። አስተካክለው እንደገና ያቅርቡ። |
| 2.3 | Accept it. Try to open another sub-city's woreda report by URL: refused. | ይቀበሉት። የሌላ ክ/ከተማ ወረዳ ሪፖርትን በአድራሻ ለመክፈት ይሞክሩ፤ አይፈቀድም። |
| 2.4 | *Sub-city consolidation*: Woreda 02 has no report after the deadline. Record it as not submitted, then certify. A snapshot is stored. | *የክ/ከተማ ማጠቃለያ*፦ ወረዳ 02 ሪፖርት አላቀረበም። እንዳላቀረበ መዝግበው ማጠቃለያውን ያረጋግጡ። |

## 3. City lock / የከተማ መቆለፊያ

| # | English | አማርኛ |
| --- | --- | --- |
| 3.1 | As the coordinator, review the sub-city and center reports. | እንደ አስተባባሪ የክ/ከተማና የማዕከል ሪፖርቶችን ይገምግሙ። |
| 3.2 | *City consolidation*: lock is refused while a sub-city has not certified. After every certification, lock: the page then says it reads from the stored consolidation. | *የከተማ ማጠቃለያ*፦ ያላረጋገጠ ክ/ከተማ እስካለ መቆለፍ አይቻልም። ሁሉም ሲያረጋግጡ ይቆልፉ። |
| 3.3 | Try to change any report of the period: refused. | የጊዜውን ማንኛውም ሪፖርት ለመቀየር ይሞክሩ፤ አይፈቀድም። |

## 4. Exports / ፋይሎችን ማውጣት

| # | English | አማርኛ |
| --- | --- | --- |
| 4.1 | On the city consolidation, export XLSX and PDF for the quarter, then for 6 months. Compare with the printed Form A and Form B: titles, column groups, ተ/ቁ, totals rows, notes. | በከተማ ማጠቃለያው XLSX እና PDF ለሩብ ዓመቱና ለ6 ወር ያውጡ። ከታተሙት ቅጽ ሀ እና ቅጽ ለ ጋር ያነጻጽሩ። |
| 4.2 | As the sub-city reviewer (no export_named), export with *Include names*: names are replaced by the role. | የስም ፈቃድ የሌለው ገምጋሚ *ስሞችን አካትት* ብሎ ሲያወጣ ስሞች በሥራ ድርሻ ይተካሉ። |

## 5. Correction after acceptance / ከተቀባይነት በኋላ ማረም

| # | English | አማርኛ |
| --- | --- | --- |
| 5.1 | Reopen the period (coordinator, with a reason). As the woreda head, open the accepted report and *Request a correction* (20+ characters). | ጊዜውን በምክንያት እንደገና ይክፈቱ። የወረዳው ኃላፊ ተቀባይነት ያገኘውን ሪፖርት ከፍቶ *የእርማት ጥያቄ* ያቀርባል። |
| 5.2 | As the reviewer, approve it: revision 2 opens as a draft; revision 1 keeps counting. Correct, submit, accept: revision 1 becomes superseded and the consolidation shows the corrected figures. | ገምጋሚው ያጸድቃል፤ ክለሳ 2 እንደ ረቂቅ ይከፈታል። ሲቀበሉት ክለሳ 1 ይተካል፤ ማጠቃለያው የታረሙትን አሃዞች ያሳያል። |

## 6. Extensions / የጊዜ ማራዘሚያ

| # | English | አማርኛ |
| --- | --- | --- |
| 6.1 | After the deadline, a draft cannot be submitted. | ከጊዜ ገደቡ በኋላ ረቂቅ ሪፖርት ማቅረብ አይቻልም። |
| 6.2 | The coordinator grants an extension on the period page (reason 20+ characters). The report can now be submitted and shows *Late (extension)* in compliance. | አስተባባሪው ማራዘሚያ ይሰጣል፤ ሪፖርቱ መቅረብ ይችላል፤ በተገዢነት *ዘግይቶ (በተራዘመ ጊዜ)* ይታያል። |
| 6.3 | Revoke another extension: that participant can no longer submit. | ሌላ ማራዘሚያ ይሰርዙ፤ ተሳታፊው ከዚያ በኋላ ማቅረብ አይችልም። |

## 7. Support / ድጋፍ

| # | English | አማርኛ |
| --- | --- | --- |
| 7.1 | The woreda head asks a clarification from the entry screen. The support officer answers; the head is notified; the thread can be closed. | የወረዳው ኃላፊ የማብራሪያ ጥያቄ ያቀርባል፤ ደጋፊ ባለሙያው ይመልሳል፤ ኃላፊው ይነገረዋል፤ ውይይቱ ሊዘጋ ይችላል። |
| 7.2 | The support officer plans a visit to Woreda 01, adds a finding and an action item due yesterday. The dashboard shows it as overdue; the next reminder run notifies. | ደጋፊ ባለሙያው ጉብኝት አቅዶ ግኝትና የትናንት ቀነ ገደብ ያለው የድርጊት ተግባር ይጨምራል፤ ዳሽቦርዱ ጊዜው እንዳለፈ ያሳያል። |
| 7.3 | The woreda head marks the action item done with a note and evidence (PDF/JPG/PNG). | የወረዳው ኃላፊ ተግባሩን በማስታወሻና በማስረጃ እንደተከናወነ ይመዘግባል። |

## 8. Situation Room / የሁኔታ መከታተያ

| # | English | አማርኛ |
| --- | --- | --- |
| 8.1 | A user with the Situation Room operations grant sees the *Service Delivery Monitoring* category: compliance, people held accountable by action, standards, overdue actions. No names appear. | የሁኔታ መከታተያ ፈቃድ ያለው ተጠቃሚ *የአገልግሎት አሰጣጥ ክትትል* ምድብን ያያል፤ ስሞች አይታዩም። |
