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

    # Build table data
    table_data = []

    # Header row
    header_row = [col['name'] for col in columns]
    table_data.append(header_row)

    # Data rows
    for result in results:
        row = []
        for col in columns:
            value = ''
            for attr in col.get('api_attributes', []):
                if attr in result and result[attr]:
                    value = str(result[attr])
                    break
            row.append(value)
        table_data.append(row)

    # Calculate column widths
    page_width = landscape(A4)[0] - 20*mm
    col_widths = calculate_column_widths(columns, page_width)

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
