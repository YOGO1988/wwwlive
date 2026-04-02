#!/usr/bin/env python3
"""
Simple PDF Generator for ChronoTrack Live Results
Uses ReportLab to generate professional PDFs
Called from PHP with JSON data
"""

import sys
import json
import os
from datetime import datetime

try:
    from reportlab.lib.pagesizes import A4, landscape
    from reportlab.lib import colors
    from reportlab.lib.units import mm
    from reportlab.platypus import SimpleDocTemplate, Table, TableStyle, Paragraph, Spacer, Image
    from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
    from reportlab.lib.enums import TA_CENTER, TA_LEFT
    from reportlab.pdfbase import pdfmetrics
    from reportlab.pdfbase.ttfonts import TTFont
except ImportError:
    print(json.dumps({"error": "ReportLab not installed. Run: pip3 install reportlab"}))
    sys.exit(1)


def country_code_to_flag(country_code):
    """Convert ISO country code to flag emoji"""
    if not country_code or len(country_code) != 2:
        return ''

    # Convert to uppercase
    country_code = country_code.upper()

    # Convert to regional indicator symbols
    # A = U+1F1E6, B = U+1F1E7, ..., Z = U+1F1FF
    first_letter = ord(country_code[0]) - ord('A')
    second_letter = ord(country_code[1]) - ord('A')

    if first_letter < 0 or first_letter > 25 or second_letter < 0 or second_letter > 25:
        return ''

    first_codepoint = 0x1F1E6 + first_letter
    second_codepoint = 0x1F1E6 + second_letter

    return chr(first_codepoint) + chr(second_codepoint)


def get_country_code(country_name):
    """Get ISO country code from country name"""
    if not country_name:
        return ''

    # Mapping of country names to ISO codes (same as country-flags.js)
    country_map = {
        'Polska': 'PL', 'Poland': 'PL',
        'Germany': 'DE', 'Niemcy': 'DE',
        'United States': 'US', 'USA': 'US', 'Stany Zjednoczone': 'US',
        'United Kingdom': 'GB', 'UK': 'GB', 'Wielka Brytania': 'GB',
        'France': 'FR', 'Francja': 'FR',
        'Italy': 'IT', 'Włochy': 'IT',
        'Spain': 'ES', 'Hiszpania': 'ES',
        'Czech Republic': 'CZ', 'Czechy': 'CZ',
        'Slovakia': 'SK', 'Słowacja': 'SK',
        'Ukraine': 'UA', 'Ukraina': 'UA',
        'Belarus': 'BY', 'Białoruś': 'BY',
        'Lithuania': 'LT', 'Litwa': 'LT',
        'Latvia': 'LV', 'Łotwa': 'LV',
        'Estonia': 'EE', 'Eesti': 'EE',
        'Russia': 'RU', 'Rosja': 'RU',
        'Netherlands': 'NL', 'Holandia': 'NL',
        'Belgium': 'BE', 'Belgia': 'BE',
        'Switzerland': 'CH', 'Szwajcaria': 'CH',
        'Austria': 'AT',
        'Hungary': 'HU', 'Węgry': 'HU',
        'Romania': 'RO', 'Rumunia': 'RO',
        'Bulgaria': 'BG', 'Bułgaria': 'BG',
        'Sweden': 'SE', 'Szwecja': 'SE',
        'Norway': 'NO', 'Norwegia': 'NO',
        'Denmark': 'DK', 'Dania': 'DK',
        'Finland': 'FI', 'Finlandia': 'FI',
        'Portugal': 'PT', 'Portugalia': 'PT',
        'Greece': 'GR', 'Grecja': 'GR',
        'Ireland': 'IE', 'Irlandia': 'IE',
        'Canada': 'CA', 'Kanada': 'CA',
        'Australia': 'AU',
        'New Zealand': 'NZ', 'Nowa Zelandia': 'NZ',
        'Japan': 'JP', 'Japonia': 'JP',
        'China': 'CN', 'Chiny': 'CN',
        'South Korea': 'KR', 'Korea Południowa': 'KR',
        'Brazil': 'BR', 'Brazylia': 'BR',
        'Argentina': 'AR', 'Argentyna': 'AR',
        'Mexico': 'MX', 'Meksyk': 'MX',
        'South Africa': 'ZA', 'RPA': 'ZA',
        'Kenya': 'KE', 'Kenia': 'KE',
        'Ethiopia': 'ET', 'Etiopia': 'ET',
    }

    # Try exact match
    if country_name in country_map:
        return country_map[country_name]

    # Try case-insensitive match
    lower_name = country_name.lower()
    for name, code in country_map.items():
        if name.lower() == lower_name:
            return code

    # If it's already a 2-letter code, return it
    if len(country_name) == 2:
        return country_name.upper()

    return ''


def generate_pdf(data):
    """Generate PDF from JSON data"""

    # Extract data
    event_name = data.get('event_name', 'Event')
    event_date = data.get('event_date', '')
    location = data.get('location', '')
    distance = data.get('distance', '')
    columns = data.get('columns', [])
    results = data.get('results', [])
    output_path = data.get('output_path', '/tmp/results.pdf')
    event_logo_url = data.get('event_logo_url', '')

    # Create PDF
    doc = SimpleDocTemplate(
        output_path,
        pagesize=landscape(A4),
        leftMargin=10*mm,
        rightMargin=10*mm,
        topMargin=28*mm,
        bottomMargin=15*mm
    )

    elements = []

    # Title
    styles = getSampleStyleSheet()
    title_style = ParagraphStyle(
        'CustomTitle',
        parent=styles['Heading1'],
        fontSize=14,
        textColor=colors.HexColor('#FF6600'),
        alignment=TA_LEFT,
        spaceAfter=6
    )

    title_text = event_name.upper()
    elements.append(Paragraph(title_text, title_style))

    # Subtitle
    subtitle_style = ParagraphStyle(
        'Subtitle',
        parent=styles['Normal'],
        fontSize=11,
        alignment=TA_LEFT,
        spaceAfter=10
    )

    subtitle_text = f"Wyniki OPEN | {distance} | {location} | {event_date}"
    elements.append(Paragraph(subtitle_text, subtitle_style))

    elements.append(Spacer(1, 3*mm))

    # Build table data with flag column support
    table_data = []

    # Header row - split multi-word headers into two lines
    header_row = []
    flag_column_index = -1  # Track where to insert flag column
    for idx, col in enumerate(columns):
        col_name = col['name']
        # Split on first space (e.g., "Msc M/K" -> "Msc\nM/K")
        if ' ' in col_name:
            parts = col_name.split(' ', 1)
            col_name = parts[0] + '\n' + parts[1]
        header_row.append(col_name)

        # Check if this is name column (to add flag after it)
        col_id = col.get('id', '')
        if col_id == 'full_name' or 'name' in col_id.lower():
            flag_column_index = idx + 1
            header_row.insert(flag_column_index, '')  # Empty header for flag

    table_data.append(header_row)

    # Data rows
    for result in results:
        # Get country flag for this athlete
        country_code = (result.get('country') or result.get('Country') or
                       result.get('nationality') or result.get('Nationality') or
                       result.get('athlete_country') or result.get('country_code') or
                       result.get('CountryCode') or '')
        flag_emoji = ''
        if country_code:
            iso_code = get_country_code(country_code)
            if iso_code:
                flag_emoji = country_code_to_flag(iso_code)

        row = []
        for col_idx, col in enumerate(columns):
            value = ''
            for attr in col.get('api_attributes', []):
                # Handle birth_year extraction
                if attr in ['birth_year', 'birthdate', 'athlete_birthdate']:
                    birth_value = (result.get(attr) or result.get('birth_year') or
                                 result.get('birthdate') or result.get('athlete_birthdate'))
                    if birth_value:
                        # Extract year from date if needed
                        if isinstance(birth_value, str) and '-' in birth_value:
                            year = birth_value.split('-')[0]
                            if len(year) == 4:
                                value = year
                                break
                        # If it's already a year
                        elif (isinstance(birth_value, (int, float)) or
                              (isinstance(birth_value, str) and len(birth_value) == 4)):
                            value = str(birth_value)
                            break

                # Regular attribute handling
                if attr in result and result[attr]:
                    value = str(result[attr])
                    break
            row.append(value)

            # Insert flag column after name column
            if flag_column_index > 0 and col_idx + 1 == flag_column_index:
                row.insert(flag_column_index, flag_emoji)

        table_data.append(row)

    # Calculate column widths (add flag column width if present)
    page_width = landscape(A4)[0] - 20*mm
    col_widths = calculate_column_widths(columns, page_width)

    # Insert flag column width (8mm) after name column
    if flag_column_index > 0:
        col_widths.insert(flag_column_index, 8*mm)

    # Create table
    table = Table(table_data, colWidths=col_widths, repeatRows=1)

    # Table style with STRIPED ROWS
    table_style = TableStyle([
        # Header style
        ('BACKGROUND', (0, 0), (-1, 0), colors.HexColor('#FF6600')),
        ('TEXTCOLOR', (0, 0), (-1, 0), colors.white),
        ('ALIGN', (0, 0), (-1, -1), 'CENTER'),
        ('FONTNAME', (0, 0), (-1, 0), 'Helvetica-Bold'),
        ('FONTSIZE', (0, 0), (-1, 0), 7),
        ('BOTTOMPADDING', (0, 0), (-1, 0), 3),
        ('TOPPADDING', (0, 0), (-1, 0), 3),

        # Data rows
        ('FONTNAME', (0, 1), (-1, -1), 'Helvetica'),
        ('FONTSIZE', (0, 1), (-1, -1), 7),
        ('BOTTOMPADDING', (0, 1), (-1, -1), 2),
        ('TOPPADDING', (0, 1), (-1, -1), 2),

        # Borders
        ('GRID', (0, 0), (-1, -1), 0.5, colors.HexColor('#CCCCCC')),
        ('BOX', (0, 0), (-1, -1), 1, colors.black),

        # STRIPED ROWS (alternating colors)
        ('ROWBACKGROUNDS', (0, 1), (-1, -1), [colors.white, colors.HexColor('#F5F5F5')]),
    ])

    table.setStyle(table_style)
    elements.append(table)

    # Build PDF
    doc.build(elements)

    return output_path


def calculate_column_widths(columns, available_width):
    """Calculate proportional column widths"""
    proportions = []

    for col in columns:
        name_lower = col['name'].lower()

        # Numbers and positions - narrow
        if any(keyword in name_lower for keyword in ['msc', 'mce', 'nr', 'start', 'kat']):
            proportions.append(0.5)
        # Times and split times - medium
        elif any(keyword in name_lower for keyword in ['czas', 'tempo', 'pk', 'meta']):
            proportions.append(0.9)
        # Names - wider
        elif any(keyword in name_lower for keyword in ['nazwisko', 'imię', 'imie']):
            proportions.append(1.5)
        # Clubs and locations - wider
        elif any(keyword in name_lower for keyword in ['miejscowość', 'klub']):
            proportions.append(1.4)
        else:
            proportions.append(1.0)

    # Calculate actual widths
    total_proportion = sum(proportions)
    widths = [(p / total_proportion) * available_width for p in proportions]

    return widths


if __name__ == '__main__':
    # Read JSON from stdin
    try:
        input_data = sys.stdin.read()
        data = json.loads(input_data)

        output_path = generate_pdf(data)

        # Return success
        print(json.dumps({
            "success": True,
            "pdf_path": output_path
        }))

    except Exception as e:
        print(json.dumps({
            "success": False,
            "error": str(e)
        }))
        sys.exit(1)
