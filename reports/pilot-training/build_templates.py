# -*- coding: utf-8 -*-
"""
Builds the two Amharic Excel templates the pilot institutions fill before
their core data is loaded into Agelgay (አገልጋይ):

  A. agelgay-structure-template-am.xlsx  -> ተቋማት › መዋቅር አስመጣ
     (sheets Organization / Organization Units / Positions, read by
      App\\Services\\OrganizationStructureImport\\StructureWorkbookReader)
  B. agelgay-employees-template-am.xlsx  -> ሠራተኞች › ከፋይል ይመዝግቡ
     (first sheet read by App\\Services\\Employees\\EmployeeCsvImportService,
      code-based header)

Header keys are the importer's own English keys: the importers match on them
and a translated header could not be uploaded back. The Amharic meaning of
every column is a cell comment on the header and a table in the መመሪያ sheet.

Run:  python reports/pilot-training/build_templates.py [--tests DIR]
--tests also writes filled copies used only to check the files against the
importers; they are never shipped.
"""
from __future__ import annotations

import sys
from pathlib import Path

from openpyxl import Workbook
from openpyxl.comments import Comment
from openpyxl.styles import Alignment, Border, Font, PatternFill, Side
from openpyxl.utils import get_column_letter
from openpyxl.worksheet.datavalidation import DataValidation

OUT_DIR = Path(__file__).resolve().parent
FONT = "Nyala"
DATA_ROWS = 500  # rows pre-formatted as text and covered by the dropdowns

GREEN = "1F4E3D"
GREEN_SOFT = "5B8C6E"
GREY = "F2F2F2"
YELLOW = "FFF2CC"
WHITE = "FFFFFF"

thin = Side(style="thin", color="BFBFBF")
BORDER = Border(left=thin, right=thin, top=thin, bottom=thin)

# Lookup values as they stand in the pilot database (psemsdb, 2026-11-22).
ORG_TYPES = [("OT-01", "City Administration", "ከተማ አስተዳደር"), ("OT-02", "City", "ማዕከል")]
UNIT_TYPES = [
    ("UT-01", "Bureau", "ቢሮ"),
    ("UT-02", "Sector", "ዘርፍ"),
    ("UT-03", "Office", "ፅህፈት ቤት"),
    ("UT-04", "Directorate", "ዳይሬክቶሬት"),
    ("UT-05", "Team", "ቡድን"),
    ("UT-06", "Advisor", "አማካሪ"),
    ("UT-07", "court", "ፍርድቤት"),
]
GRADES = ["I", "II", "III", "IV", "V", "VI", "VII", "VIII", "IX", "X", "XI", "XII", "XIII", "XIV",
          "XV", "XVI", "XVII", "XVIII", "XIX", "XX", "XXI", "XXII", "ሹመት", "ኬርየር"]
STATUS_ORG = ["active", "draft", "inactive"]
STATUS_UNIT = ["active", "draft", "inactive"]
STATUS_POSITION = ["active", "inactive"]

EMPLOYMENT_STATUS = [("active", "ንቁ (መደበኛ)"), ("draft", "ረቂቅ"), ("suspended", "የታገደ"),
                     ("transferred", "የተዛወረ"), ("retired", "ጡረታ የወጣ"), ("terminated", "የተሰናበተ"),
                     ("deceased", "የሞተ")]
EMPLOYMENT_TYPE = [("permanent", "ቋሚ"), ("contract", "ኮንትራት"), ("temporary", "ጊዚያዊ"),
                   ("probation", "የሙከራ ጊዜ"), ("daily_labor", "የቀን ሠራተኛ"), ("intern", "ተለማማጅ"),
                   ("other", "ሌላ")]
MARITAL = [("single", "ያላገባ/ች"), ("married", "ያገባ/ች"), ("divorced", "የተፋታ/ች"), ("widowed", "ባል/ሚስት የሞተበት/ባት")]
DISABILITY_TYPE = [("physical", "አካላዊ"), ("visual", "የእይታ"), ("hearing", "የመስማት"), ("intellectual", "የአእምሮ"),
                   ("psychosocial", "የሥነ ልቦና"), ("multiple", "ብዙ ዓይነት"), ("other", "ሌላ")]
GENDER = [("ወንድ", "ወንድ (ወይም male)"), ("ሴት", "ሴት (ወይም female)")]
YES_NO = [("yes", "አዎ"), ("no", "አይ")]


# ─────────────────────────── helpers ────────────────────────────────────────

def font(bold=False, color="000000", size=11, italic=False):
    return Font(name=FONT, bold=bold, color=color, size=size, italic=italic)


def header_row(ws, columns, required: set[str], comments: dict[str, str], widths: dict[str, int] | None = None):
    """Write the key header in row 1: required keys on dark green, optional on soft green."""
    for idx, key in enumerate(columns, start=1):
        cell = ws.cell(row=1, column=idx, value=key)
        cell.font = font(bold=True, color=WHITE)
        cell.fill = PatternFill("solid", fgColor=GREEN if key in required else GREEN_SOFT)
        cell.alignment = Alignment(vertical="center", wrap_text=True)
        cell.border = BORDER
        if key in comments:
            text = ("ግዴታ · " if key in required else "አማራጭ · ") + comments[key]
            c = Comment(text, "Agelgay")
            c.width, c.height = 320, 140
            cell.comment = c
        ws.column_dimensions[get_column_letter(idx)].width = (widths or {}).get(key, 22)
    ws.row_dimensions[1].height = 30
    ws.freeze_panes = "A2"


def text_format(ws, first_col: int, last_col: int, rows: int = DATA_ROWS):
    """Store every data cell as text so Excel keeps leading zeros and typed dates."""
    for r in range(2, rows + 2):
        for c in range(first_col, last_col + 1):
            cell = ws.cell(row=r, column=c)
            cell.number_format = "@"
            cell.font = font()
            cell.border = BORDER


def list_validation(ws, col_letter: str, formula: str, rows: int = DATA_ROWS, allow_blank=True, error="ከዝርዝሩ ይምረጡ"):
    dv = DataValidation(type="list", formula1=formula, allow_blank=allow_blank, showErrorMessage=True,
                        errorTitle="የማይፈቀድ እሴት", error=error, showDropDown=False)
    ws.add_data_validation(dv)
    dv.add(f"{col_letter}2:{col_letter}{rows + 1}")
    return dv


def write_lookup_block(ws, start_col: int, title: str, header: list[str], rows: list[tuple]):
    """A titled list on the lookup sheet; returns the absolute range of its first column."""
    ws.cell(row=1, column=start_col, value=title).font = font(bold=True, color=WHITE)
    ws.cell(row=1, column=start_col).fill = PatternFill("solid", fgColor=GREEN)
    for j in range(1, len(header)):
        ws.cell(row=1, column=start_col + j).fill = PatternFill("solid", fgColor=GREEN)
    for j, h in enumerate(header):
        c = ws.cell(row=2, column=start_col + j, value=h)
        c.font = font(bold=True)
        c.fill = PatternFill("solid", fgColor=GREY)
        c.border = BORDER
        ws.column_dimensions[get_column_letter(start_col + j)].width = 22 if j else 18
    for i, row in enumerate(rows, start=3):
        for j, v in enumerate(row):
            c = ws.cell(row=i, column=start_col + j, value=v)
            c.font = font()
            c.border = BORDER
            c.number_format = "@"
    col = get_column_letter(start_col)
    return f"${col}$3:${col}${2 + len(rows)}"


def guide_sheet(wb, title: str, intro: list[str], sections: list[tuple[str, list]], first=True):
    """
    A read-only Amharic guide. `sections` is a list of (heading, body) where body
    is a list of strings (paragraphs / numbered steps) or a list of tuples
    (table rows; the first tuple is the table header).
    """
    ws = wb.create_sheet("መመሪያ", 0 if first else None)
    ws.sheet_view.showGridLines = False
    for col, width in zip("ABCDEF", (30, 46, 16, 60, 4, 4)):
        ws.column_dimensions[col].width = width

    r = 1
    ws.cell(row=r, column=1, value=title).font = font(bold=True, size=16, color=GREEN)
    ws.merge_cells(start_row=r, start_column=1, end_row=r, end_column=4)
    r += 1
    for para in intro:
        c = ws.cell(row=r, column=1, value=para)
        c.font = font()
        c.alignment = Alignment(wrap_text=True, vertical="top")
        ws.merge_cells(start_row=r, start_column=1, end_row=r, end_column=4)
        ws.row_dimensions[r].height = max(18, 16 * (1 + len(para) // 110))
        r += 1
    r += 1

    for heading, body in sections:
        c = ws.cell(row=r, column=1, value=heading)
        c.font = font(bold=True, size=13, color=WHITE)
        for col in range(1, 5):
            ws.cell(row=r, column=col).fill = PatternFill("solid", fgColor=GREEN)
        ws.merge_cells(start_row=r, start_column=1, end_row=r, end_column=4)
        r += 1
        if body and isinstance(body[0], tuple):
            for i, row in enumerate(body):
                for j, v in enumerate(row, start=1):
                    cell = ws.cell(row=r, column=j, value=v)
                    cell.border = BORDER
                    cell.alignment = Alignment(wrap_text=True, vertical="top")
                    if i == 0:
                        cell.font = font(bold=True)
                        cell.fill = PatternFill("solid", fgColor=GREY)
                    else:
                        cell.font = font()
                        if j == 1:
                            cell.font = font(bold=True)
                longest = max(len(str(v or "")) for v in row)
                ws.row_dimensions[r].height = max(18, 16 * (1 + longest // 55))
                r += 1
        else:
            for para in body:
                cell = ws.cell(row=r, column=1, value=para)
                cell.font = font()
                cell.alignment = Alignment(wrap_text=True, vertical="top")
                ws.merge_cells(start_row=r, start_column=1, end_row=r, end_column=4)
                ws.row_dimensions[r].height = max(18, 16 * (1 + len(para) // 105))
                r += 1
        r += 1
    return ws


# ─────────────────────────── Workbook A: structure ─────────────────────────

ORG_COLS = ["organization_code", "organization_name_en", "organization_name_am",
            "organization_type_code", "parent_organization_code", "status"]
ORG_REQUIRED = {"organization_name_en", "organization_name_am", "organization_type_code"}
ORG_COMMENTS = {
    "organization_code": "ባዶ ይተዉ፤ ሥርዓቱ ኮድ ያመነጫል። ተቋሙ ቀድሞ የተመዘገበ ከሆነ ብቻ ያለውን ኮድ ይጻፉ (ለምሳሌ ማ-002)።",
    "organization_name_en": "የተቋሙ ስም በእንግሊዝኛ። የእንግሊዝኛ ስም ከሌለ አማርኛውን እዚህም ይጻፉ።",
    "organization_name_am": "የተቋሙ ሙሉ ስም በአማርኛ፤ በሕጋዊ ሰነድ እንደተጻፈ።",
    "organization_type_code": "የተቋም ዓይነት ኮድ ከ«ኮዶች» ሉህ (ለምሳሌ OT-02)።",
    "parent_organization_code": "ተቋሙ የሚገኝበት የበላይ ተቋም ኮድ (ለምሳሌ ከአ-001 ለከተማ አስተዳደሩ)። አማራጭ።",
    "status": "active ይጻፉ። ባዶ ከተተወ active ይሆናል።",
}

UNIT_HELPER = "ረድፍ (#)"
UNIT_COLS = [UNIT_HELPER, "unit_code", "unit_name_en", "unit_name_am", "unit_type_code", "parent_unit_code",
             "host_organization_code", "functional_owner_organization_code", "status"]
UNIT_REQUIRED = {"unit_name_en", "unit_type_code"}
UNIT_COMMENTS = {
    UNIT_HELPER: "ይህ ዓምድ የሚያሳየው የዚህ ረድፍ ማጣቀሻ ነው (#2፣ #3 …)። ለparent_unit_code እና በPositions ሉህ ለunit_code ይህን ይቀዱ። አይጻፉበት፤ ሥርዓቱ አያነበውም።",
    "unit_code": "ባዶ ይተዉ፤ ሥርዓቱ ኮድ ያመነጫል (ለምሳሌ UNIT-0095)። በእጅ የተጻፈ ኮድ አይፈቀድም።",
    "unit_name_en": "የሥራ ክፍሉ ስም በእንግሊዝኛ። የእንግሊዝኛ ስም ከሌለ አማርኛውን እዚህም ይጻፉ።",
    "unit_name_am": "የሥራ ክፍሉ ስም በአማርኛ (በሥርዓቱ ላይ የሚታየው)።",
    "unit_type_code": "የክፍል ዓይነት ኮድ ከ«ኮዶች» ሉህ (UT-01 ቢሮ፣ UT-04 ዳይሬክቶሬት፣ UT-05 ቡድን …)።",
    "parent_unit_code": "የበላይ ክፍሉ ረድፍ ማጣቀሻ፦ # እና የዚያ ረድፍ ቁጥር (ለምሳሌ #2)። ለከፍተኛው ክፍል ባዶ ይተዉ። ቀድሞ የተመዘገበ ክፍል ከሆነ ኮዱን (UNIT-0012) መጻፍ ይቻላል።",
    "host_organization_code": "ክፍሉ በሌላ ተቋም ውስጥ የሚገኝ (የሚስተናገድ) ከሆነ ብቻ የዚያ ተቋም ኮድ። በተለምዶ ባዶ።",
    "functional_owner_organization_code": "ክፍሉ በተግባር የሌላ ተቋም ከሆነ ብቻ የባለቤቱ ተቋም ኮድ። በተለምዶ ባዶ።",
    "status": "active ይጻፉ። ባዶ ከተተወ active ይሆናል።",
}

POS_COLS = ["position_code", "standard_name", "bpr_name", "old_code", "unit_code", "job_grade_code",
            "profession_code", "position_status", "slots"]
POS_REQUIRED = {"standard_name", "unit_code", "slots"}
POS_COMMENTS = {
    "position_code": "ባዶ ይተዉ፤ ሥርዓቱ ኮድ ያመነጫል (ለምሳሌ ማ-002/12)። በእጅ የተጻፈ ኮድ አይፈቀድም።",
    "standard_name": "የሥራ መደቡ መደበኛ መጠሪያ (ለምሳሌ «ከፍተኛ የሰው ሀብት ባለሙያ»)። በአንድ ክፍል ለአንድ መጠሪያ አንድ ረድፍ፤ ብዛቱን በslots ይጻፉ።",
    "bpr_name": "በBPR/በመዋቅር ጥናቱ የተሰጠው መጠሪያ ከመደበኛው የሚለይ ከሆነ። አማራጭ።",
    "old_code": "ተቋሙ ሲጠቀምበት የነበረው የመደብ ኮድ/ቁጥር። አማራጭ ነገር ግን ይመከራል፤ ሠራተኞችን ለማዛመድ ይረዳል።",
    "unit_code": "መደቡ የሚገኝበት የሥራ ክፍል ረድፍ ማጣቀሻ ከ«Organization Units» ሉህ (ለምሳሌ #3)። ቀድሞ የተመዘገበ ክፍል ከሆነ ኮዱን (UNIT-0012)።",
    "job_grade_code": "የመደቡ ደረጃ ከ«ኮዶች» ሉህ (I … XXII፣ ሹመት፣ ኬርየር)። አማራጭ።",
    "profession_code": "የሙያ (ISCO) ኮድ ከሥርዓቱ የሙያ ዝርዝር። በፓይለቱ ባዶ ይተዉ።",
    "position_status": "active ወይም inactive። ባዶ ከተተወ active።",
    "slots": "በዚህ መደብ የተፈቀዱ የመቀመጫ ብዛት (ሙሉ ቁጥር ≥ 1)። ለምሳሌ 5 ባለሙያዎች = 5። ባዶ ከተተወ 1።",
}


def build_structure(path: Path, test_fill: str | None = None):
    wb = Workbook()
    wb.remove(wb.active)

    # ── ኮዶች (lookup) ──
    lk = wb.create_sheet("ኮዶች")
    rng_org_type = write_lookup_block(lk, 1, "የተቋም ዓይነት (organization_type_code)", ["ኮድ", "English", "አማርኛ"], ORG_TYPES)
    rng_unit_type = write_lookup_block(lk, 5, "የክፍል ዓይነት (unit_type_code)", ["ኮድ", "English", "አማርኛ"], UNIT_TYPES)
    rng_grade = write_lookup_block(lk, 9, "የመደብ ደረጃ (job_grade_code)", ["ደረጃ"], [(g,) for g in GRADES])
    rng_status = write_lookup_block(lk, 11, "ሁኔታ (status)", ["እሴት", "ትርጉም"],
                                    [("active", "ንቁ"), ("draft", "ረቂቅ"), ("inactive", "ንቁ ያልሆነ")])
    rng_pstatus = write_lookup_block(lk, 14, "የመደብ ሁኔታ (position_status)", ["እሴት", "ትርጉም"],
                                     [("active", "ንቁ"), ("inactive", "ንቁ ያልሆነ")])
    note = lk.cell(row=1, column=17, value="ማስታወሻ፦ እነዚህ ዝርዝሮች ከፓይለቱ ዳታቤዝ የተወሰዱ ናቸው (22/11/2026 እ.ኤ.አ.)። "
                                            "በሥርዓቱ ላይ ቅንብሮች › የሰው ሀብት መሠረታዊ መረጃ ውስጥ ካሉት ጋር ያወዳድሩ፤ ሌላ ኮድ ካለ ከዚያው ይጠቀሙ።")
    note.font = font(italic=True)
    note.alignment = Alignment(wrap_text=True, vertical="top")
    lk.column_dimensions["Q"].width = 60
    lk.row_dimensions[1].height = 60

    # ── Organization ──
    org = wb.create_sheet("Organization")
    header_row(org, ORG_COLS, ORG_REQUIRED, ORG_COMMENTS,
               {"organization_name_en": 40, "organization_name_am": 40, "organization_type_code": 24, "parent_organization_code": 26})
    text_format(org, 1, len(ORG_COLS), rows=5)
    list_validation(org, "D", f"='ኮዶች'!{rng_org_type}", rows=5)
    list_validation(org, "F", f"='ኮዶች'!{rng_status}", rows=5)
    for c in range(1, len(ORG_COLS) + 1):
        org.cell(row=2, column=c).fill = PatternFill("solid", fgColor=YELLOW)
    # Outside the read columns (A–F): a cell under a known header would count as a data row.
    org.cell(row=2, column=8, value="← አንድ ተቋም ብቻ፤ ረድፍ 2 ላይ ይጻፉ። ሌላ ረድፍ አይጨምሩ።").font = font(italic=True, color="7F7F7F")
    org.column_dimensions["H"].width = 48

    # ── Organization Units ──
    units = wb.create_sheet("Organization Units")
    header_row(units, UNIT_COLS, UNIT_REQUIRED, UNIT_COMMENTS,
               {UNIT_HELPER: 10, "unit_name_en": 38, "unit_name_am": 38, "parent_unit_code": 18,
                "host_organization_code": 24, "functional_owner_organization_code": 30})
    text_format(units, 2, len(UNIT_COLS))
    for r in range(2, DATA_ROWS + 2):
        cell = units.cell(row=r, column=1, value=f'="#"&ROW()')
        cell.font = font(color="7F7F7F")
        cell.fill = PatternFill("solid", fgColor=GREY)
        cell.border = BORDER
    list_validation(units, "E", f"='ኮዶች'!{rng_unit_type}")
    list_validation(units, "I", f"='ኮዶች'!{rng_status}")

    # ── Positions ──
    pos = wb.create_sheet("Positions")
    header_row(pos, POS_COLS, POS_REQUIRED, POS_COMMENTS,
               {"standard_name": 40, "bpr_name": 30, "old_code": 16, "unit_code": 14, "job_grade_code": 16,
                "profession_code": 16, "position_status": 16, "slots": 10})
    text_format(pos, 1, len(POS_COLS))
    for r in range(2, DATA_ROWS + 2):  # slots is a number
        pos.cell(row=r, column=9).number_format = "0"
    list_validation(pos, "F", f"='ኮዶች'!{rng_grade}")
    list_validation(pos, "H", f"='ኮዶች'!{rng_pstatus}")
    dv = DataValidation(type="whole", operator="greaterThanOrEqual", formula1="1", allow_blank=True,
                        showErrorMessage=True, errorTitle="ብዛት", error="ሙሉ ቁጥር ≥ 1 ይጻፉ")
    pos.add_data_validation(dv)
    dv.add(f"I2:I{DATA_ROWS + 1}")

    # ── መመሪያ ──
    guide_sheet(
        wb,
        "አገልጋይ — ሉህ A፦ የተቋም መዋቅር (ተቋም፣ የሥራ ክፍሎች፣ የሥራ መደቦች)",
        [
            "ይህ ፋይል የተቋሙን መገለጫ፣ የሥራ ክፍሎቹን (መዋቅሩን) እና የሥራ መደቦቹን በአንድ ጊዜ ወደ ሥርዓቱ ያስገባል። "
            "በሥርዓቱ ላይ፦ ተቋማት › «መዋቅር አስመጣ» › ፋይሉን ይጫኑ › ቅድመ እይታውን ይመልከቱ › «ምዝገባውን ያረጋግጡ»።",
            "ሠራተኞች በዚህ ፋይል አይገቡም። የሥራ መደቦቹ ከተመዘገቡና ኮዶቻቸው ከተመነጩ በኋላ በሉህ B (የሠራተኞች ዝርዝር) ይገባሉ።",
            "ቅድመ እይታው ምንም አያስቀምጥም፤ ስህተት ካለ በሉህ፣ በረድፍና በአምድ ይነግርዎታል። ሁሉም ስህተቶች እስኪስተካከሉ ምዝገባው አይፈቀድም።",
        ],
        [
            ("ዋና ደንቦች", [
                "1. የሉሆቹን ስሞች (Organization፣ Organization Units፣ Positions) እና የመጀመሪያውን ረድፍ (የአምድ ስሞች) አይቀይሩ፤ አይተርጉሙ። «መመሪያ» እና «ኮዶች» ሉሆች ለእርስዎ ብቻ ናቸው፤ ሥርዓቱ አያነባቸውም።",
                "2. የኮድ አምዶች (organization_code፣ unit_code፣ position_code) ባዶ ይተዉ። ኮዶቹ በሥርዓቱ የኮድ ደንቦች በራስ-ሰር ይመነጫሉ፤ በእጅ የተጻፈ ኮድ ይከለከላል። ልዩ ሁኔታ፦ ተቋሙ ራሱ ቀድሞ ተመዝግቦ ከሆነ organization_code ላይ የነበረውን ኮድ ይጻፉ (ክፍሎችና መደቦች ተጨማሪ ይገባሉ)።",
                "3. ኮድ ስለሌለ ክፍሎች እርስ በርስ የሚገናኙት በረድፍ ማጣቀሻ ነው፦ «#» እና የExcel ረድፉ ቁጥር። በOrganization Units ሉህ ዓምድ A (ረድፍ #) የእያንዳንዱን ረድፍ ማጣቀሻ ያሳያል (#2፣ #3 …)። የበላይ ክፍልን በparent_unit_code ላይ፣ የመደቡን ክፍል በPositions ሉህ unit_code ላይ በዚሁ መልክ ይጻፉ።",
                "4. ክፍሎችን ከላይ ወደ ታች ይጻፉ፦ መጀመሪያ የተቋሙ ከፍተኛ ክፍሎች (parent_unit_code ባዶ)፣ ከዚያ በሥራቸው ያሉት። አንድ ክፍል የራሱ የበላይ መሆን አይችልም፤ ዙር (A→B→A) አይፈቀድም።",
                "5. የሥራ መደቦች፦ በአንድ ክፍል ለአንድ መጠሪያ አንድ ረድፍ ብቻ፤ የተፈቀዱትን መቀመጫዎች ብዛት በslots ይጻፉ (ለምሳሌ 4 ባለሙያዎች = slots 4)። 300 ሠራተኛ ያለው ተቋም ብዙ ጊዜ 60–100 ረድፍ ብቻ ይፈልጋል።",
                "6. የዓይነት፣ የደረጃና የሁኔታ ኮዶች ከ«ኮዶች» ሉህ ይመረጣሉ (በሕዋሱ ላይ ተቆልቋይ ዝርዝር አለ)። ሌላ ጽሑፍ ከተጻፈ ስህተት ይሆናል።",
                "7. ስሞች፦ የእንግሊዝኛ ስም (…_en) ግዴታ ነው፤ የእንግሊዝኛ ስም ከሌለ አማርኛውን በሁለቱም ዓምዶች ይጻፉ። የአማርኛው ስም በሥርዓቱ ላይ የሚታየው ነው።",
                "8. ፋይሉን እንደ Excel (.xlsx) ያስቀምጡ። ተጨማሪ ዓምዶች ቢጨመሩ ይታለፋሉ፤ ባዶ ረድፎች ይዘለላሉ።",
            ]),
            ("ከመሙላት በፊት ይዘው የሚመጡት", [
                "• የተቋሙ የመዋቅር ቻርት (የክፍሎች ዝርዝር ከበላይ-ታች ግንኙነት ጋር)።",
                "• የተፈቀደው የሥራ መደብ ዝርዝር (መጠሪያ፣ ክፍል፣ ደረጃ፣ የተፈቀደ ብዛት፣ የቀድሞ ኮድ)።",
                "• ተቋሙ በሥርዓቱ ላይ ቀድሞ ተመዝግቦ እንደሆነ፣ ኮዱን (ለምሳሌ ማ-002) ከሥርዓቱ አስተዳዳሪ ይጠይቁ።",
            ]),
            ("ሉህ Organization — አንድ ረድፍ ብቻ (ረድፍ 2)", [
                ("አምድ", "ትርጉም", "ግዴታ?", "የሚፈቀዱ እሴቶች / ምሳሌ"),
                ("organization_code", "የተቋም ኮድ", "አይ", "ባዶ (ይመነጫል)። ቀድሞ የተመዘገበ ተቋም ከሆነ ብቻ ያለውን ኮድ፣ ለምሳሌ ማ-002"),
                ("organization_name_en", "ስም በእንግሊዝኛ", "አዎ", "Youth and Sports Bureau"),
                ("organization_name_am", "ስም በአማርኛ", "አዎ", "ወጣቶችና ስፖርት ቢሮ"),
                ("organization_type_code", "የተቋም ዓይነት ኮድ", "አዎ", "ከ«ኮዶች» ሉህ፤ ለምሳሌ OT-02"),
                ("parent_organization_code", "የበላይ ተቋም ኮድ", "አይ", "ከአ-001 (የአዲስ አበባ ከተማ አስተዳደር)"),
                ("status", "ሁኔታ", "አይ", "active"),
            ]),
            ("ሉህ Organization Units — በአንድ ረድፍ አንድ የሥራ ክፍል", [
                ("አምድ", "ትርጉም", "ግዴታ?", "የሚፈቀዱ እሴቶች / ምሳሌ"),
                ("ረድፍ (#)", "የረድፉ ማጣቀሻ (በራስ-ሰር)", "—", "አይጻፉበት። #2፣ #3 … ያሳያል"),
                ("unit_code", "የክፍል ኮድ", "አይ", "ባዶ (ይመነጫል)"),
                ("unit_name_en", "የክፍሉ ስም በእንግሊዝኛ", "አዎ", "Human Resource Management Directorate"),
                ("unit_name_am", "የክፍሉ ስም በአማርኛ", "ይመከራል", "የሰው ሀብት አስተዳደር ዳይሬክቶሬት"),
                ("unit_type_code", "የክፍል ዓይነት ኮድ", "አዎ", "UT-04 (ዳይሬክቶሬት)፣ UT-05 (ቡድን) … ከ«ኮዶች» ሉህ"),
                ("parent_unit_code", "የበላይ ክፍል", "አይ", "#2 (የበላይ ክፍሉ ረድፍ)። ለከፍተኛ ክፍል ባዶ"),
                ("host_organization_code", "አስተናጋጅ ተቋም ኮድ", "አይ", "በተለምዶ ባዶ"),
                ("functional_owner_organization_code", "የተግባር ባለቤት ተቋም ኮድ", "አይ", "በተለምዶ ባዶ"),
                ("status", "ሁኔታ", "አይ", "active"),
            ]),
            ("ምሳሌ — Organization Units (ረድፍ 2–5)", [
                ("ረድፍ", "unit_name_am", "unit_type_code", "parent_unit_code"),
                ("#2", "የቢሮ ኃላፊ ጽ/ቤት", "UT-03", "(ባዶ)"),
                ("#3", "የሰው ሀብት አስተዳደር ዳይሬክቶሬት", "UT-04", "#2"),
                ("#4", "የሰው ሀብት ልማት ቡድን", "UT-05", "#3"),
                ("#5", "የፋይናንስ ዳይሬክቶሬት", "UT-04", "#2"),
            ]),
            ("ሉህ Positions — በአንድ ረድፍ አንድ የሥራ መደብ መጠሪያ (ከብዛቱ ጋር)", [
                ("አምድ", "ትርጉም", "ግዴታ?", "የሚፈቀዱ እሴቶች / ምሳሌ"),
                ("position_code", "የመደብ ኮድ", "አይ", "ባዶ (ይመነጫል)፤ ለምሳሌ ማ-002/12 ይሆናል"),
                ("standard_name", "የመደቡ መደበኛ መጠሪያ", "አዎ", "ከፍተኛ የሰው ሀብት ባለሙያ"),
                ("bpr_name", "የBPR መጠሪያ", "አይ", "ከመደበኛው የሚለይ ከሆነ ብቻ"),
                ("old_code", "የቀድሞ የመደብ ኮድ", "ይመከራል", "ተቋሙ ሲጠቀምበት የነበረው ቁጥር"),
                ("unit_code", "የሥራ ክፍል", "አዎ", "#3 (ከOrganization Units ሉህ ረድፍ)"),
                ("job_grade_code", "ደረጃ", "አይ", "I … XXII፣ ሹመት፣ ኬርየር ከ«ኮዶች» ሉህ"),
                ("profession_code", "የሙያ ኮድ", "አይ", "በፓይለቱ ባዶ"),
                ("position_status", "ሁኔታ", "አይ", "active ወይም inactive"),
                ("slots", "የተፈቀዱ መቀመጫዎች ብዛት", "አዎ", "ሙሉ ቁጥር ≥ 1፤ ለምሳሌ 4"),
            ]),
            ("ምሳሌ — Positions", [
                ("standard_name", "unit_code", "job_grade_code", "slots"),
                ("የሰው ሀብት አስተዳደር ዳይሬክተር", "#3", "XVI", "1"),
                ("ከፍተኛ የሰው ሀብት ባለሙያ", "#4", "XII", "4"),
                ("የሰው ሀብት ባለሙያ", "#4", "X", "6"),
                ("ዋና የሂሳብ ሹም", "#5", "XIV", "1"),
            ]),
            ("የተለመዱ ስህተቶች", [
                "• የአምድ ስም መቀየር/መተርጎም → «የሚያስፈልግ አምድ ጠፍቷል»። ረድፍ 1 እንዳለ ይተዉ።",
                "• unit_code ወይም position_code ላይ የራስ ኮድ መጻፍ → «በእጅ የተጻፈ ኮድ አይፈቀድም»። ባዶ ይተዉ።",
                "• parent_unit_code ላይ ስም መጻፍ → ስህተት። ማጣቀሻ (#3) ወይም ነባር ኮድ (UNIT-0012) ብቻ።",
                "• ረድፍ ከተሰረዘ ወይም ከተገባ በኋላ ማጣቀሻዎቹ መቀየር — ዓምድ A (ረድፍ #) ያሳያል፤ parent_unit_code እና unit_code ን እንደገና ያረጋግጡ።",
                "• ለእያንዳንዱ ሠራተኛ አንድ መደብ ረድፍ መጻፍ — አያስፈልግም፤ slots ይጠቀሙ።",
                "• ፋይሉን እንደ .csv ማስቀመጥ — ሉሆቹ ይጠፋሉ። .xlsx ብቻ።",
            ]),
            ("ከምዝገባ በኋላ", [
                "ቅድመ እይታው ለእያንዳንዱ ክፍልና መደብ የተመነጨውን ኮድ ያሳያል (ለምሳሌ UNIT-0095፣ ማ-002/12)። ምዝገባውን ካረጋገጡ በኋላ እነዚህ ኮዶች በተቋማት › የሥራ ክፍሎች እና በሥራ መደቦች ዝርዝር ላይ ይታያሉ።",
                "የሠራተኞችን ዝርዝር (ሉህ B) ለመሙላት የሚያስፈልጉት እነዚሁ የክፍልና የመደብ ኮዶች ናቸው።",
            ]),
        ],
    )
    wb.active = 0

    if test_fill:
        _fill_structure_test(wb, test_fill)

    wb.save(path)
    return path


def _fill_structure_test(wb, mode: str):
    """Rows used only to check the file against the importer. Never shipped."""
    org = wb["Organization"]
    if mode == "existing":
        org["A2"] = "ማ-002"
        org["B2"] = "ወጣቶችና ስፖርት ቢሮ"
        org["C2"] = "ወጣቶችና ስፖርት ቢሮ"
    else:
        org["B2"] = "Pilot Import Check Bureau"
        org["C2"] = "የፓይለት ማስመጫ ፍተሻ ቢሮ"
    org["D2"] = "OT-02"
    org["E2"] = "ከአ-001"
    org["F2"] = "active"

    units = wb["Organization Units"]
    rows = [
        ("Head Office", "የቢሮ ኃላፊ ጽ/ቤት", "UT-03", None),
        ("Human Resource Management Directorate", "የሰው ሀብት አስተዳደር ዳይሬክቶሬት", "UT-04", "#2"),
        ("Human Resource Development Team", "የሰው ሀብት ልማት ቡድን", "UT-05", "#3"),
        ("Finance Directorate", "የፋይናንስ ዳይሬክቶሬት", "UT-04", "#2"),
    ]
    for i, (en, am, t, parent) in enumerate(rows, start=2):
        units.cell(row=i, column=3, value=en)
        units.cell(row=i, column=4, value=am)
        units.cell(row=i, column=5, value=t)
        if parent:
            units.cell(row=i, column=6, value=parent)
        units.cell(row=i, column=9, value="active")

    pos = wb["Positions"]
    prow = [
        ("የሰው ሀብት አስተዳደር ዳይሬክተር", "#3", "XVI", 1, "HR-01"),
        ("ከፍተኛ የሰው ሀብት ባለሙያ", "#4", "XII", 4, "HR-02"),
        ("የሰው ሀብት ባለሙያ", "#4", "X", 6, None),
        ("ዋና የሂሳብ ሹም", "#5", "XIV", 1, "FIN-01"),
    ]
    for i, (name, unit, grade, slots, old) in enumerate(prow, start=2):
        pos.cell(row=i, column=2, value=name)
        pos.cell(row=i, column=5, value=unit)
        pos.cell(row=i, column=6, value=grade)
        pos.cell(row=i, column=8, value="active")
        pos.cell(row=i, column=9, value=slots)
        if old:
            pos.cell(row=i, column=4, value=old)


# ─────────────────────────── Workbook B: employees ─────────────────────────

EMP_COLS = ["first_name", "father_name", "grandfather_name", "gender", "phone", "email",
            "organization_code", "organization_unit_code", "position_code", "employment_status",
            "assignment_start_date", "nationality", "employment_type",
            "name_en", "national_id", "date_of_birth", "marital_status", "education_level", "field_of_study",
            "has_disability", "disability_type", "service_start_date", "address",
            "emergency_contact_name", "emergency_contact_phone"]
EMP_HELPERS = ["የሥራ ክፍል ስም (ማስታወሻ)", "የሥራ መደብ ስም (ማስታወሻ)", "የቀድሞ መደብ ኮድ (ማስታወሻ)"]
EMP_REQUIRED = {"first_name", "father_name", "grandfather_name", "gender", "organization_code", "position_code", "employment_status"}
EMP_COMMENTS = {
    "first_name": "የሠራተኛው ስም (የራሱ ስም ብቻ)።",
    "father_name": "የአባት ስም።",
    "grandfather_name": "የአያት ስም። ያለ አያት ስም ምዝገባው አይቀበልም።",
    "gender": "ወንድ ወይም ሴት (male / female ም ይቻላል)።",
    "phone": "ስልክ ቁጥር እንደ ጽሑፍ፤ ለምሳሌ 0911000000። የመጀመሪያው 0 አይጠፋም።",
    "email": "ኢሜይል (ትክክለኛ ቅርጸት)። አማራጭ።",
    "organization_code": "የተቋሙ ኮድ በሥርዓቱ (ለምሳሌ ማ-002)። ለሁሉም ረድፎች አንድ ነው።",
    "organization_unit_code": "የሥራ ክፍሉ ኮድ (ለምሳሌ UNIT-0095)። አማራጭ፤ ከተጻፈ መደቡ በዚያ ክፍል መሆን አለበት።",
    "position_code": "የሥራ መደቡ ኮድ በሥርዓቱ (ለምሳሌ ማ-002/12)። ከሥራ መደቦች ዝርዝር ይቀዱ። መደቡ ክፍት መቀመጫ ሊኖረው ይገባል።",
    "employment_status": "active (ለአሁኑ ሠራተኞች)። ሌሎች፦ suspended፣ retired፣ terminated … «allowed_values» ይመልከቱ።",
    "assignment_start_date": "በዚህ መደብ የተመደበበት ቀን፤ ቀን/ወር/ዓመት (ለምሳሌ 05/01/2018)። በፋይሉ ሁሉ አንድ የቀን አቆጣጠር። ባዶ ከሆነ ዛሬ።",
    "nationality": "ዜግነት፤ ለምሳሌ ኢትዮጵያዊ። አማራጭ።",
    "employment_type": "permanent (ቋሚ)፣ contract፣ temporary፣ probation፣ daily_labor፣ intern፣ other።",
    "name_en": "ሙሉ ስም በእንግሊዝኛ ፊደል (ለመታወቂያ ካርድ)። አማራጭ።",
    "national_id": "የፋይዳ ቁጥር — 16 አኃዝ ብቻ፤ እንደ ጽሑፍ።",
    "date_of_birth": "የትውልድ ቀን፤ ቀን/ወር/ዓመት፤ ከassignment_start_date ጋር አንድ አቆጣጠር።",
    "marital_status": "single፣ married፣ divorced፣ widowed።",
    "education_level": "የትምህርት ደረጃ ስም በሥርዓቱ እንደተመዘገበው (ቅንብሮች › የትምህርት ደረጃዎች)። ሌላ ጽሑፍ ስህተት ነው።",
    "field_of_study": "የትምህርት መስክ፤ ለምሳሌ ማኔጅመንት። አማራጭ።",
    "has_disability": "yes ወይም no (አዎ/አይ)። ባዶ = አልተገለጸም።",
    "disability_type": "physical፣ visual፣ hearing፣ intellectual፣ psychosocial፣ multiple፣ other። has_disability yes ሲሆን ግዴታ።",
    "service_start_date": "በመንግሥት አገልግሎት የተቀጠረበት የመጀመሪያ ቀን፤ ቀን/ወር/ዓመት።",
    "address": "የመኖሪያ አድራሻ። አማራጭ።",
    "emergency_contact_name": "የአደጋ ጊዜ ተጠሪ ስም። አማራጭ።",
    "emergency_contact_phone": "የአደጋ ጊዜ ተጠሪ ስልክ (ቁጥሮች ብቻ)።",
    EMP_HELPERS[0]: "ለእርስዎ ማስታወሻ ብቻ — ሥርዓቱ አያነበውም። ከነባሩ ዝርዝር የክፍሉን ስም ይቀዱ፤ ከዚያ ኮዱን በorganization_unit_code ይጻፉ።",
    EMP_HELPERS[1]: "ለእርስዎ ማስታወሻ ብቻ — ሥርዓቱ አያነበውም። ከነባሩ ዝርዝር የመደቡን መጠሪያ ይቀዱ፤ ከዚያ ኮዱን በposition_code ይጻፉ።",
    EMP_HELPERS[2]: "ለእርስዎ ማስታወሻ ብቻ። በሉህ A old_code ላይ የጻፉት ኮድ፤ መደቡን ለማግኘት ይረዳል።",
}


def build_employees(path: Path):
    wb = Workbook()
    wb.remove(wb.active)

    # The importer reads the FIRST sheet, so the data sheet must come first.
    emp = wb.create_sheet("employees")
    cols = EMP_COLS + EMP_HELPERS
    header_row(emp, cols, EMP_REQUIRED, EMP_COMMENTS,
               {"email": 26, "organization_unit_code": 22, "position_code": 18, "assignment_start_date": 20,
                "employment_type": 18, "name_en": 28, "national_id": 20, "education_level": 22, "field_of_study": 22,
                "address": 30, "emergency_contact_name": 24, "emergency_contact_phone": 20,
                EMP_HELPERS[0]: 30, EMP_HELPERS[1]: 30, EMP_HELPERS[2]: 18})
    text_format(emp, 1, len(cols))
    for i, key in enumerate(cols, start=1):
        if key in EMP_HELPERS:
            for r in range(2, DATA_ROWS + 2):
                emp.cell(row=r, column=i).fill = PatternFill("solid", fgColor=GREY)
    # employment_status is 'active' for everyone being loaded; rows with no person stay "unfilled" and are skipped.
    for r in range(2, DATA_ROWS + 2):
        emp.cell(row=r, column=10, value="active")

    # ── allowed_values ──
    av = wb.create_sheet("allowed_values")
    rng_gender = write_lookup_block(av, 1, "gender (ጾታ)", ["እሴት", "ትርጉም"], GENDER)
    rng_estatus = write_lookup_block(av, 4, "employment_status", ["እሴት", "ትርጉም"], EMPLOYMENT_STATUS)
    rng_etype = write_lookup_block(av, 7, "employment_type", ["እሴት", "ትርጉም"], EMPLOYMENT_TYPE)
    rng_marital = write_lookup_block(av, 10, "marital_status", ["እሴት", "ትርጉም"], MARITAL)
    rng_yesno = write_lookup_block(av, 13, "has_disability", ["እሴት", "ትርጉም"], YES_NO)
    rng_dis = write_lookup_block(av, 16, "disability_type", ["እሴት", "ትርጉም"], DISABILITY_TYPE)
    write_lookup_block(av, 19, "education_level", ["ስም"], [("(ከሥርዓቱ ቅንብሮች › የትምህርት ደረጃዎች ዝርዝር ይቀዱ)",)])
    av.column_dimensions["S"].width = 48

    list_validation(emp, "D", f"='allowed_values'!{rng_gender}", error="ወንድ ወይም ሴት ይጻፉ")
    list_validation(emp, "J", f"='allowed_values'!{rng_estatus}")
    list_validation(emp, "M", f"='allowed_values'!{rng_etype}")
    list_validation(emp, "Q", f"='allowed_values'!{rng_marital}")
    list_validation(emp, "T", f"='allowed_values'!{rng_yesno}", error="yes ወይም no ይጻፉ")
    list_validation(emp, "U", f"='allowed_values'!{rng_dis}")

    # ── mapping from the institution's own list ──
    mp = wb.create_sheet("ከነባር ዝርዝር ወደ ቴምፕሌት")
    for col, width in zip("ABC", (34, 34, 70)):
        mp.column_dimensions[col].width = width
    mp["A1"] = "ተቋማት ይዘው የሚመጡት የሠራተኛ ዝርዝር ብዙ ጊዜ በዚህ ቅርጸት አይደለም። ይህ ሠንጠረዥ የተለመዱ ዓምዶች እንዴት ወደ ቴምፕሌቱ እንደሚቀየሩ ያሳያል።"
    mp["A1"].font = font()
    mp["A1"].alignment = Alignment(wrap_text=True, vertical="top")
    mp.merge_cells("A1:C1")
    mp.row_dimensions[1].height = 34
    mapping = [
        ("በነባሩ ዝርዝር ያለው", "በቴምፕሌቱ የሚገባበት", "እንዴት ይቀየራል"),
        ("ሙሉ ስም በአንድ ሕዋስ (አበበ ከበደ ተስፋዬ)", "first_name / father_name / grandfather_name",
         "Excel፦ ዓምዱን ይምረጡ › Data › Text to Columns › Delimited › Space። ሦስት ዓምድ ይሆናል። የአያት ስም የሌለው ሠራተኛ ካለ ከፋይሉ ላይ ይጠይቁ፤ ባዶ አይፈቀድም።"),
        ("ጾታ፦ ወ / ሴ ወይም M / F", "gender", "Find & Replace፦ ወ → ወንድ፣ ሴ → ሴት (M → male፣ F → female)። ባዶ አይፈቀድም።"),
        ("የሥራ ክፍል ስም", "organization_unit_code (+ ማስታወሻ ዓምድ)",
         "ስሙን በ«የሥራ ክፍል ስም (ማስታወሻ)» ይቀዱ። ሉህ A ከተመዘገበ በኋላ በተቋማት › የሥራ ክፍሎች ላይ ያለውን ኮድ (UNIT-0095) በorganization_unit_code ይጻፉ።"),
        ("የሥራ መደብ መጠሪያ", "position_code (+ ማስታወሻ ዓምድ)",
         "መጠሪያውን በ«የሥራ መደብ ስም (ማስታወሻ)» ይቀዱ። በሥራ መደቦች ዝርዝር ላይ የዚያ ክፍል የዚያ መጠሪያ ኮድ (ማ-002/12) ያግኙና በposition_code ይጻፉ። ተመሳሳይ መደብ ያላቸው ሠራተኞች አንድ ኮድ ይጋራሉ (እስከ slots ብዛት)።"),
        ("የቅጥር ቀን በኢትዮጵያ አቆጣጠር (ለምሳሌ 05/01/2008 ወይም ጥር 5 2008)", "service_start_date",
         "ቀን/ወር/ዓመት በቁጥር ብቻ፦ 05/01/2008። ሕዋሱ ጽሑፍ ነው፤ Excel እንዳይቀይረው ያረጋግጡ። በመጫን ገጹ ላይ «የኢትዮጵያ ቀን አቆጣጠር» ይምረጡ። በፋይሉ ውስጥ አቆጣጠር አይቀላቅሉ።"),
        ("በአሁኑ መደብ የተመደበበት ቀን", "assignment_start_date", "እንደ ላይኛው። ካልታወቀ ባዶ ይተዉ (ዛሬ ይሆናል)።"),
        ("የትውልድ ቀን / ዕድሜ", "date_of_birth", "ቀን ካለ ቀን/ወር/ዓመት። ዕድሜ ብቻ ከሆነ ባዶ ይተዉ።"),
        ("ስልክ 911 000 000", "phone", "ቁጥሮች ብቻ ከመጀመሪያው 0 ጋር፦ 0911000000። ሕዋሱ ጽሑፍ ነው።"),
        ("የቅጥር ሁኔታ፦ ቋሚ / ኮንትራት / ጊዚያዊ", "employment_type", "permanent / contract / temporary (ዝርዝሩ በallowed_values)።"),
        ("የትምህርት ደረጃ፦ ዲግሪ፣ ዲፕሎማ …", "education_level", "በሥርዓቱ የተመዘገበውን ስም በትክክል ይጻፉ (ቅንብሮች › የትምህርት ደረጃዎች)። ዝርዝሩ ባዶ ከሆነ ዓምዱን ባዶ ይተዉ።"),
        ("የትምህርት መስክ", "field_of_study", "እንዳለ ይቀዱ።"),
        ("ፋይዳ / ብሔራዊ መታወቂያ ቁጥር", "national_id", "16 አኃዝ ብቻ፤ ክፍተትና ሰረዝ ይወገዳል። ከ16 ያነሰ/የበለጠ ከሆነ ባዶ ይተዉ።"),
        ("የሠራተኛ መለያ ቁጥር (የተቋሙ)", "— (አይገባም)", "የሠራተኛ ቁጥር በሥርዓቱ ይመነጫል። የቀድሞውን ቁጥር ለማስታወሻ በሌላ ዓምድ ማስቀመጥ ይችላሉ፤ ይታለፋል።"),
        ("ደመወዝ፣ ደረጃ", "— (አይገባም)", "ደረጃ የመደቡ ባሕርይ ነው (ሉህ A job_grade_code)። ደመወዝ በዚህ ፋይል አይገባም።"),
    ]
    for i, row in enumerate(mapping, start=3):
        for j, v in enumerate(row, start=1):
            c = mp.cell(row=i, column=j, value=v)
            c.border = BORDER
            c.alignment = Alignment(wrap_text=True, vertical="top")
            c.font = font(bold=(i == 3 or j == 1))
            if i == 3:
                c.fill = PatternFill("solid", fgColor=GREY)
        mp.row_dimensions[i].height = max(18, 16 * (1 + max(len(str(v)) for v in row) // 60))

    # ── መመሪያ (second sheet; opens first) ──
    guide = guide_sheet(
        wb,
        "አገልጋይ — ሉህ B፦ የሠራተኞች ዝርዝር",
        [
            "ይህ ፋይል የተቋሙን ሠራተኞች ከምደባቸው ጋር ወደ ሥርዓቱ ያስገባል። በሥርዓቱ ላይ፦ ሠራተኞች › «ሠራተኞችን ከፋይል ይመዝግቡ» › ፋይሉን ይጫኑ › "
            "የቀን አቆጣጠሩን ይምረጡ › «ፋይሉን ይፈትሹ» › ውጤቱን አይተው «ሠራተኞችን ይመዝግቡ»።",
            "ቅድመ ሁኔታ፦ ሉህ A (መዋቅር) ተመዝግቦ የሥራ ክፍሎችና የሥራ መደቦች ኮድ አግኝተው መሆን አለባቸው። ሠራተኛ የሚመደበው ክፍት መቀመጫ ባለው መደብ ላይ ብቻ ነው።",
            "መፈተሽ ምንም አያስቀምጥም። ስህተት ያለባቸው ረድፎች ከረድፍ ቁጥርና ከማስተካከያው ጋር ይታያሉ፤ «እነዚህን ረድፎች ያውርዱ» ብለው አስተካክለው እንደገና ይጫኑ። ትክክለኛ ረድፎች ወዲያው ይመዘገባሉ።",
            "ማስታወሻ፦ የሥርዓቱ ገጽ ራሱ ለተቋሙ የተዘጋጀ ቴምፕሌት (በአንድ ረድፍ አንድ ክፍት መደብ) ያወርዳል። ያም ይሠራል፤ ይህ ፋይል ግን ከነባር ዝርዝር ለመገልበጥ የተመቸ ነው — ኮድ ላይ የተመሠረተ።",
        ],
        [
            ("ዋና ደንቦች", [
                "1. የመጀመሪያው ሉህ (employees) መጀመሪያ መሆን አለበት፤ ሥርዓቱ የመጀመሪያውን ሉህ ብቻ ያነባል። የመጀመሪያውን ረድፍ (የአምድ ስሞች) አይቀይሩ፤ አይተርጉሙ።",
                "2. ግዴታ የሆኑት፦ first_name፣ father_name፣ grandfather_name፣ gender፣ organization_code፣ position_code፣ employment_status (active ቀድሞ ተሞልቷል)።",
                "3. ኮዶቹ (ተቋም፣ ክፍል፣ መደብ) ከሥርዓቱ ይቀዱ — ሉህ A ከተመዘገበ በኋላ በተቋማት › የሥራ ክፍሎች እና በሥራ መደቦች ዝርዝር ላይ ይታያሉ። ስም ሳይሆን ኮድ ነው የሚገባው።",
                "4. ቀኖች ሁሉ (assignment_start_date፣ date_of_birth፣ service_start_date) ቀን/ወር/ዓመት (05/01/2018) በአንድ አቆጣጠር ይጻፉ፤ የትኛው እንደሆነ በመጫን ገጹ ላይ ይመረጣል። የወደፊት ቀን ስህተት ነው።",
                "5. ስልክ፣ ፋይዳ ቁጥርና ኮዶች ጽሑፍ ናቸው — የመጀመሪያው 0 እንዳይጠፋ ሕዋሶቹ ቀድሞ እንደ ጽሑፍ ተቀርጸዋል። ቁጥሮችን ከሌላ ፋይል ሲገለብጡ Paste › Values ይጠቀሙ።",
                "6. የምርጫ ዓምዶች (gender፣ employment_status፣ employment_type፣ marital_status፣ has_disability፣ disability_type) ከ«allowed_values» ሉህ ብቻ፤ ተቆልቋይ ዝርዝር አለ።",
                "7. ግራጫ ዓምዶች (ማስታወሻ) ለእርስዎ ብቻ ናቸው፤ ሥርዓቱ አያነባቸውም። ሰው ያልተጻፈባቸው ረድፎች ይዘለላሉ።",
                "8. ከአንድ ፋይል ቢበዛ 2,000 ረድፍ። ከዛ የሚበልጥ ተቋም ፋይሉን ይከፋፍል (ለምሳሌ በዳይሬክቶሬት)።",
                "9. በአንድ መደብ ከslots ብዛት በላይ ሠራተኛ ከተመደበ «መደቡ ተይዟል» ስህተት ይመጣል — በሉህ A ያለውን slots ያስተካክሉ ወይም ተጨማሪ መደብ ያስመዝግቡ።",
            ]),
            ("አምዶች", [
                ("አምድ", "ትርጉም", "ግዴታ?", "የሚፈቀዱ እሴቶች / ምሳሌ"),
                ("first_name", "ስም", "አዎ", "አበበ"),
                ("father_name", "የአባት ስም", "አዎ", "ከበደ"),
                ("grandfather_name", "የአያት ስም", "አዎ", "ተስፋዬ"),
                ("gender", "ጾታ", "አዎ", "ወንድ / ሴት"),
                ("phone", "ስልክ", "አይ", "0911000000"),
                ("email", "ኢሜይል", "አይ", "abebe.kebede@example.com"),
                ("organization_code", "የተቋም ኮድ", "አዎ", "ማ-002"),
                ("organization_unit_code", "የሥራ ክፍል ኮድ", "አይ", "UNIT-0095"),
                ("position_code", "የሥራ መደብ ኮድ", "አዎ", "ማ-002/12"),
                ("employment_status", "የመዝገብ ሁኔታ", "አዎ", "active"),
                ("assignment_start_date", "በመደቡ የተመደበበት ቀን", "አይ", "05/01/2018 (ባዶ = ዛሬ)"),
                ("nationality", "ዜግነት", "አይ", "ኢትዮጵያዊ"),
                ("employment_type", "የቅጥር ዓይነት", "አይ", "permanent / contract / temporary / probation / daily_labor / intern / other"),
                ("name_en", "ስም በእንግሊዝኛ", "አይ", "Abebe Kebede Tesfaye"),
                ("national_id", "የፋይዳ ቁጥር", "አይ", "16 አኃዝ"),
                ("date_of_birth", "የትውልድ ቀን", "አይ", "12/03/1980"),
                ("marital_status", "የጋብቻ ሁኔታ", "አይ", "single / married / divorced / widowed"),
                ("education_level", "የትምህርት ደረጃ", "አይ", "በሥርዓቱ የተመዘገበው ስም"),
                ("field_of_study", "የትምህርት መስክ", "አይ", "ማኔጅመንት"),
                ("has_disability", "አካል ጉዳተኝነት", "አይ", "yes / no"),
                ("disability_type", "የአካል ጉዳት ዓይነት", "yes ሲሆን", "physical / visual / hearing / intellectual / psychosocial / multiple / other"),
                ("service_start_date", "የአገልግሎት መጀመሪያ ቀን", "አይ", "01/11/2005"),
                ("address", "አድራሻ", "አይ", "ቦሌ ክ/ከ ወረዳ 03"),
                ("emergency_contact_name", "የአደጋ ጊዜ ተጠሪ ስም", "አይ", "አልማዝ ተስፋዬ"),
                ("emergency_contact_phone", "የአደጋ ጊዜ ተጠሪ ስልክ", "አይ", "0911111111"),
            ]),
            ("ምሳሌ — ሁለት ረድፍ (በኢትዮጵያ አቆጣጠር)", [
                ("first_name / father_name / grandfather_name", "gender · phone", "organization_code · position_code", "employment_type · service_start_date"),
                ("አበበ · ከበደ · ተስፋዬ", "ወንድ · 0911000000", "ማ-002 · ማ-002/12", "permanent · 01/11/2005"),
                ("ሐና · ግርማ · ወልደ", "ሴት · 0922000000", "ማ-002 · ማ-002/12", "contract · 15/02/2014"),
            ]),
            ("የተለመዱ ስህተቶች", [
                "• የአያት ስም ባዶ → ረድፉ አይመዘገብም። ከተቋሙ ይጠይቁ።",
                "• በposition_code ላይ የመደቡ ስም መጻፍ → «መደብ አልተገኘም»። ኮዱን ይጻፉ።",
                "• የጎርጎርዮስ ቀን በኢትዮጵያ አቆጣጠር መምረጥ → ቀኑ 7–8 ዓመት ይዘገያል፤ «የወደፊት ቀን» ስህተት ይመጣል። አቆጣጠሩን ያረጋግጡ።",
                "• ስልክ 911000000 (0 ጠፍቷል) → ሕዋሱ ቁጥር ሆኗል። ዓምዱን ጽሑፍ አድርጎ 0 ይመልሱ።",
                "• አንድ ሰው ሁለት ጊዜ (ተመሳሳይ ስምና ስልክ) → «በፋይሉ ተደጋግሟል»።",
                "• education_level ላይ በሥርዓቱ የሌለ ስም → ስህተት። ባዶ ይተዉ ወይም ትክክለኛውን ስም ይጻፉ።",
            ]),
        ],
        first=False,
    )
    wb.active = wb.index(guide)
    wb.save(path)
    return path


# ─────────────────────────── main ──────────────────────────────────────────

def main(argv: list[str]) -> int:
    tests_dir = None
    if "--tests" in argv:
        tests_dir = Path(argv[argv.index("--tests") + 1])
        tests_dir.mkdir(parents=True, exist_ok=True)

    a = build_structure(OUT_DIR / "agelgay-structure-template-am.xlsx")
    b = build_employees(OUT_DIR / "agelgay-employees-template-am.xlsx")
    print("built", a)
    print("built", b)

    if tests_dir:
        print("test", build_structure(tests_dir / "A-test-new.xlsx", test_fill="new"))
        print("test", build_structure(tests_dir / "A-test-existing.xlsx", test_fill="existing"))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
