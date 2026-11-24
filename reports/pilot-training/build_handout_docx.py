# -*- coding: utf-8 -*-
"""
Builds the trainees' handout: the five-day programme on one landscape A4
page (two at most), for printing and handing out.

Run:  python reports/pilot-training/build_handout_docx.py
Output: reports/pilot-training/Agelgay_ToT_Handout_Amharic.docx

Colours other than body text are blue and orange only. Times are in the
Ethiopian clock. Content follows build_programme_docx.py.
"""
from pathlib import Path

from docx import Document
from docx.enum.section import WD_ORIENT
from docx.enum.table import WD_TABLE_ALIGNMENT, WD_CELL_VERTICAL_ALIGNMENT
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Cm, Pt, RGBColor

OUT = Path(__file__).resolve().parent / "Agelgay_ToT_Handout_Amharic.docx"
BODY, HEAD = "Nyala", "Ebrima"
BLUE, ORANGE = "1F4E9A", "E87722"
BLUE_RGB, ORANGE_RGB = RGBColor(0x1F, 0x4E, 0x9A), RGBColor(0xE8, 0x77, 0x22)
WHITE = RGBColor(0xFF, 0xFF, 0xFF)
BAND, ORANGE_SOFT, BORDER = "EEF3FA", "FDF0E4", "A9B8D6"


def run(par, text, *, bold=False, size=10, color=None, font=BODY):
    r = par.add_run(text)
    r.font.name = font
    r.font.size = Pt(size)
    r.bold = bold
    if color is not None:
        r.font.color.rgb = color
    rpr = r._element.get_or_add_rPr()
    rf = rpr.find(qn("w:rFonts"))
    if rf is None:
        rf = OxmlElement("w:rFonts")
        rpr.append(rf)
    for a in ("w:ascii", "w:hAnsi", "w:cs", "w:eastAsia"):
        rf.set(qn(a), font)
    return r


def shade(cell, fill):
    tcpr = cell._element.get_or_add_tcPr()
    shd = OxmlElement("w:shd")
    shd.set(qn("w:val"), "clear")
    shd.set(qn("w:color"), "auto")
    shd.set(qn("w:fill"), fill)
    tcpr.append(shd)


def margins(cell, v=60, h=90):
    tcpr = cell._element.get_or_add_tcPr()
    mar = OxmlElement("w:tcMar")
    for side, val in (("top", v), ("left", h), ("bottom", v), ("right", h)):
        el = OxmlElement(f"w:{side}")
        el.set(qn("w:w"), str(val))
        el.set(qn("w:type"), "dxa")
        mar.append(el)
    tcpr.append(mar)


def borders(table, color=BORDER):
    b = OxmlElement("w:tblBorders")
    for side in ("top", "left", "bottom", "right", "insideH", "insideV"):
        el = OxmlElement(f"w:{side}")
        el.set(qn("w:val"), "single")
        el.set(qn("w:sz"), "4")
        el.set(qn("w:space"), "0")
        el.set(qn("w:color"), color)
        b.append(el)
    table._element.tblPr.append(b)


def rule(par, color=ORANGE, size=12):
    ppr = par._element.get_or_add_pPr()
    pb = OxmlElement("w:pBdr")
    el = OxmlElement("w:bottom")
    el.set(qn("w:val"), "single")
    el.set(qn("w:sz"), str(size))
    el.set(qn("w:space"), "3")
    el.set(qn("w:color"), color)
    pb.append(el)
    ppr.append(pb)


def cell_text(cell, text, *, bold=False, size=10, color=None, font=BODY, align=WD_ALIGN_PARAGRAPH.LEFT):
    par = cell.paragraphs[0]
    par.alignment = align
    par.paragraph_format.space_after = Pt(0)
    par.paragraph_format.line_spacing = 1.05
    run(par, text, bold=bold, size=size, color=color, font=font)


doc = Document()
sec = doc.sections[0]
sec.orientation = WD_ORIENT.LANDSCAPE
sec.page_width, sec.page_height = Cm(29.7), Cm(21.0)
sec.left_margin = sec.right_margin = Cm(1.4)
sec.top_margin = sec.bottom_margin = Cm(1.2)
W = 26.9
doc.styles["Normal"].font.name = BODY
doc.styles["Normal"].font.size = Pt(10)
doc.styles["Normal"].paragraph_format.space_after = Pt(0)

# ── heading ──
p = doc.add_paragraph()
run(p, "የአዲስ አበባ ከተማ አስተዳደር ፐብሊክ ሰርቪስና የሰው ሀብት ልማት ቢሮ", size=10, color=RGBColor(0x55, 0x5B, 0x6E), font=HEAD)
p = doc.add_paragraph()
run(p, "የአሠልጣኞች ሥልጠና", bold=True, size=18, color=BLUE_RGB, font=HEAD)
run(p, "   የአምስት ቀን መርሐ ግብር — ለሠልጣኞች", bold=True, size=13, color=ORANGE_RGB, font=HEAD)
rule(p)
p = doc.add_paragraph()
p.alignment = WD_ALIGN_PARAGRAPH.JUSTIFY
p.paragraph_format.space_before = Pt(4)
p.paragraph_format.space_after = Pt(5)
run(p, "ዓላማ፦ ", bold=True, color=BLUE_RGB)
run(p, "በአምስቱ ቀናት የተቋምዎን የሥራ ክፍሎች፣ የሥራ መደቦች፣ ሠራተኞች እና ተጠቃሚዎች በተቀናጀ የሠራተኛ አስተዳደርና አገልግሎት ሥርዓት (አገልጋይ) ውስጥ "
       "አስገብተው ያጠናቅቃሉ፤ የተቋምዎን ተጠቃሚዎች ራስዎ ማሠልጠን የሚያስችልዎትን ክህሎትም ያገኛሉ። ጠዋት አጭር ማብራሪያ ይሰጣል፤ ቀሪው ጊዜ "
       "በራስዎ ተቋም መረጃ ላይ የሚሠሩበት የተግባር ሥራ ነው።")

# ── daily timetable strip ──
strip = doc.add_table(rows=1, cols=6)
strip.alignment = WD_TABLE_ALIGNMENT.CENTER
strip.autofit = False
borders(strip, ORANGE)
slots = [("የዕለቱ ሰዓት", None), ("ጠዋት 2:30–4:00", "ክለሳ እና ማብራሪያ"), ("4:00–4:15", "ዕረፍት"), ("4:15–6:00", "በተግባር ማሳየት እና ልምምድ"),
         ("6:00–7:30", "የምሳ ዕረፍት"), ("ከሰዓት 7:30–11:00", "የተግባር ሥራ እና የዕለቱ ማጠቃለያ")]
for c, (a, b), w in zip(strip.rows[0].cells, slots, (3.1, 4.6, 2.6, 5.6, 3.4, 7.6)):
    c.width = Cm(w)
    margins(c, 50, 80)
    c.vertical_alignment = WD_CELL_VERTICAL_ALIGNMENT.CENTER
    par = c.paragraphs[0]
    par.alignment = WD_ALIGN_PARAGRAPH.CENTER
    par.paragraph_format.space_after = Pt(0)
    if b is None:
        shade(c, ORANGE)
        run(par, a, bold=True, size=10, color=WHITE, font=HEAD)
    else:
        shade(c, ORANGE_SOFT)
        run(par, a, bold=True, size=9.5, color=ORANGE_RGB, font=HEAD)
        run(par, "  " + b, size=9.5)
gap = doc.add_paragraph()
gap.paragraph_format.space_after = Pt(2)
run(gap, " ", size=3)

# ── the five days ──
DAYS = [
    ("ቀን 1", "ትውውቅ እና የቀረበ መረጃ ግምገማ",
     "የሥልጠናው ዓላማ እና ሥርዓቱ ይተዋወቃሉ። በራስዎ መለያ ወደ ሥርዓቱ ይገባሉ፤ የይለፍ ቃልዎን ይቀይራሉ። መሠረታዊ መረጃ የሚገባበት ቅደም ተከተል ይብራራል።",
     "ይዘው የመጡት መረጃ ከአሠልጣኝ ጋር ይገመገማል፤ የጎደለው ይለያል። የተቋምዎን የሥራ ክፍሎች በሥርዓቱ ላይ መመዝገብ ይጀምራሉ።",
     "በመለያዎ ገብተዋል፤ የሥራ ክፍሎች ምዝገባ ተጀምሯል።"),
    ("ቀን 2", "የሥራ ክፍሎች እና የሥራ መደቦች",
     "የተቋም አደረጃጀት በሥርዓቱ የሚቀመጥበት መንገድ ይብራራል። የሥራ መደቦች ከፋይል የሚመዘገቡበት ሂደት በተግባር ይታያል። የሥራ ክፍሎች ምዝገባ ይጠናቀቃል።",
     "የሥራ መደብ ፋይሉን ከሥርዓቱ ያወርዳሉ፤ መጠሪያ፣ የሙያ ኮድ እና የሥራ ደረጃ ሞልተው ያስፈትሻሉ፤ ከአሠልጣኝ ጋር አይተው ይመዘግባሉ።",
     "የሥራ ክፍሎች እና የሥራ መደቦች ተመዝግበዋል።"),
    ("ቀን 3", "የሠራተኞች መረጃ",
     "የሠራተኛ መዝገብና ምደባ ይብራራሉ። ነባር የሠራተኞች ዝርዝር ወደ ሥርዓቱ ፋይል የሚገለበጥበት መንገድ በተግባር ይታያል። የሠራተኞች ፋይሉን ከሥርዓቱ ያወርዳሉ።",
     "እያንዳንዱን ሠራተኛ በሚይዘው የሥራ መደብ ረድፍ ላይ ይጽፋሉ፤ ፋይሉን አስፈትሸው ስህተቶቹን አርመው ይመዘግባሉ። ያልተመዘገቡትን ከምክንያታቸው ጋር ይዘረዝራሉ።",
     "ከሠራተኞቹ ቢያንስ ዘጠና አምስት በመቶው ተመዝግበዋል።"),
    ("ቀን 4", "ተጠቃሚዎች፣ ሚናዎች እና የተቋም ቅንብሮች",
     "ተጠቃሚ፣ ሚና እና የሥራ ወሰን ይብራራሉ። ከሠራተኛ መዝገብ ተጠቃሚ መፍጠር በተግባር ይታያል። ለተቋምዎ አስተዳዳሪ፣ የሰው ሀብት ኦፊሰሮች እና የሥራ ክፍል ኃላፊዎች መለያ ይፈጥራሉ።",
     "የሥራ ቀናት፣ የተቋም መገለጫ፣ የደብዳቤ መዝገብ ቤት እና የተቋሙ ዋና ዋና አገልግሎቶች ይሞላሉ። የተፈጠሩት መለያዎች ተገብቶባቸው ይፈተሻሉ።",
     "ተጠቃሚዎች ተፈጥረዋል፤ የተቋሙ ቅንብሮች ተሟልተዋል።"),
    ("ቀን 5", "ቀጣይ ሞጁሎች፣ አሠልጣኝነት እና ማጠናቀቂያ",
     "በቀጣይ ተግባራዊ የሚደረጉ ሞጁሎች ይተዋወቃሉ። ተጠቃሚዎችን እንዴት ማሠልጠን እንደሚቻል ውይይት ይደረጋል። የተቋምዎን የተጠቃሚዎች ሥልጠና ዕቅድ ያዘጋጃሉ።",
     "የማጠናቀቂያ ማረጋገጫ ዝርዝሩ ከአሠልጣኝ ጋር በሥርዓቱ ላይ እየታየ ይረጋገጣል፤ ይፈረማል። የሥልጠና ግምገማ ቅጽ ይሞላል፤ የድጋፍ ዕቅዱ ይገለጻል።",
     "የማረጋገጫ ዝርዝሩ ተፈርሟል፤ የሥልጠና ዕቅድ ተዘጋጅቷል።"),
]
widths = (1.6, 4.3, 8.0, 8.0, 5.0)
t = doc.add_table(rows=1, cols=5)
t.alignment = WD_TABLE_ALIGNMENT.CENTER
t.autofit = False
borders(t)
for c, text, w in zip(t.rows[0].cells, ("ቀን", "ርዕስ", "ጠዋት (2:30–6:00)", "ከሰዓት (7:30–11:00)", "በዕለቱ መጨረሻ"), widths):
    c.width = Cm(w)
    shade(c, BLUE)
    margins(c, 70)
    cell_text(c, text, bold=True, size=10.5, color=WHITE, font=HEAD, align=WD_ALIGN_PARAGRAPH.CENTER)
for i, (day, title, am, pm, result) in enumerate(DAYS):
    row = t.add_row()
    trpr = row._element.get_or_add_trPr()
    cs = OxmlElement("w:cantSplit")
    cs.set(qn("w:val"), "true")
    trpr.append(cs)
    for j, (c, w) in enumerate(zip(row.cells, widths)):
        c.width = Cm(w)
        margins(c)
        c.vertical_alignment = WD_CELL_VERTICAL_ALIGNMENT.CENTER
        if i % 2:
            shade(c, BAND)
    shade(row.cells[0], ORANGE_SOFT)
    cell_text(row.cells[0], day, bold=True, size=11, color=ORANGE_RGB, font=HEAD, align=WD_ALIGN_PARAGRAPH.CENTER)
    cell_text(row.cells[1], title, bold=True, size=10, color=BLUE_RGB, font=HEAD)
    cell_text(row.cells[2], am, size=9.5, align=WD_ALIGN_PARAGRAPH.JUSTIFY)
    cell_text(row.cells[3], pm, size=9.5, align=WD_ALIGN_PARAGRAPH.JUSTIFY)
    cell_text(row.cells[4], result, size=9.5)
gap = doc.add_paragraph()
run(gap, " ", size=3)

# ── bottom boxes ──
box = doc.add_table(rows=1, cols=3)
box.alignment = WD_TABLE_ALIGNMENT.CENTER
box.autofit = False
borders(box)
BOXES = [
    ("ይዘው የሚመጡት",
     ["የተቋሙ የአደረጃጀት ሠንጠረዥ", "የተፈቀደው የሥራ መደብ ዝርዝር ከሥራ ክፍሉ እና ከሥራ ደረጃው ጋር", "የሠራተኞች ዝርዝር በኤክሴል፤ ስም፣ ጾታ፣ የሥራ መደብ፣ የቅጥር ቀን፣ ስልክ",
      "የተቋሙ ዓርማ እና የሚሰጣቸው አገልግሎቶች ዝርዝር", "ኤክሴል የተጫነበት ኮምፒዩተር"]),
    ("ማስታወስ ያለብዎት",
     ["የሥራ መደብ እና የሠራተኞች ፋይሎችን ከሥርዓቱ ያውርዱ፤ የዓምድ ስሞቹን አይለውጡ።", "ምዝገባ ወደ ኋላ አይመለስም፤ ከመመዝገብዎ በፊት የፍተሻ ውጤቱን ከአሠልጣኝ ጋር ይመልከቱ።",
      "ቀኖችን በቀን፣ በወር፣ በዓመት ቅደም ተከተል በአንድ የዘመን አቆጣጠር ይጻፉ።", "የይለፍ ቃልዎን ለማንም አያጋሩ፤ የሠራተኞች ፋይል የግል መረጃ ስለያዘ ከሥልጠናው በኋላ ይሰረዛል።",
      "ከሁለተኛው ቀን ጀምሮ በየዕለቱ አንድ ሠልጣኝ የተማረውን ለሌሎች በአሥር ደቂቃ መልሶ ያስተምራል።"]),
    ("ድጋፍ",
     ["ከሥልጠናው በኋላ ለሁለት ሳምንት በስልክ እና በመልእክት መለዋወጫ ቡድን ድጋፍ ይሰጣል።", "የድጋፍ ስልክ፦ ..........................................", "የመልእክት ቡድን፦ ..........................................",
      "የተቋምዎ አሠልጣኝ፦ ..........................................", "የሥልጠናው ቦታ፦ .........................................."]),
]
for c, (title, items), w in zip(box.rows[0].cells, BOXES, (8.4, 11.0, 7.5)):
    c.width = Cm(w)
    margins(c, 80, 110)
    par = c.paragraphs[0]
    par.paragraph_format.space_after = Pt(2)
    run(par, title, bold=True, size=10.5, color=ORANGE_RGB, font=HEAD)
    rule(par, BLUE, 6)
    for item in items:
        ip = c.add_paragraph()
        ip.paragraph_format.space_after = Pt(1)
        ip.paragraph_format.left_indent = Cm(0.35)
        ip.paragraph_format.first_line_indent = Cm(-0.35)
        run(ip, "• ", bold=True, size=9.5, color=BLUE_RGB)
        run(ip, item, size=9.5)

doc.save(OUT)
print("saved", OUT)
