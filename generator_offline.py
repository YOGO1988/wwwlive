import tkinter as tk
from tkinter import ttk, filedialog, messagebox
import csv
from reportlab.lib.pagesizes import A4, landscape
from reportlab.platypus import SimpleDocTemplate, Table, TableStyle, Paragraph, Image
from reportlab.lib import colors
from reportlab.lib.styles import ParagraphStyle
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.lib.units import mm
import os
import re

class ResultsGeneratorApp:
    def __init__(self, root):
        self.root = root
        self.root.title("Generator PDF - Inteligentny Adaptive Layout")
        self.root.geometry("700x550")
        
        # Zmienne do przechowywania danych
        self.csv_path = tk.StringVar()
        self.race_name = tk.StringVar()
        self.race_date = tk.StringVar()
        self.race_distance = tk.StringVar()
        self.race_location = tk.StringVar()
        self.document_type = tk.StringVar(value="Lista Startowa")
        
        # Zmienne dla logotypów
        self.logo_path = tk.StringVar()
        self.logo1_path = tk.StringVar()
        
        self.create_widgets()
        
    def create_widgets(self):
        # Frame dla wyboru pliku CSV
        file_frame = ttk.LabelFrame(self.root, text="Wybór pliku CSV", padding="10")
        file_frame.pack(fill="x", padx=10, pady=5)
        
        ttk.Label(file_frame, text="Wybrany plik:").pack(side="left")
        ttk.Entry(file_frame, textvariable=self.csv_path, state='readonly', width=35).pack(side="left", padx=5)
        ttk.Button(file_frame, text="Wybierz plik", command=self.select_csv).pack(side="left")
        
        # Frame dla danych zawodów
        race_frame = ttk.LabelFrame(self.root, text="Dane zawodów", padding="10")
        race_frame.pack(fill="x", padx=10, pady=5)
        
        # Nazwa biegu
        ttk.Label(race_frame, text="Nazwa biegu:").grid(row=0, column=0, sticky="e", padx=5, pady=2)
        ttk.Entry(race_frame, textvariable=self.race_name, width=40).grid(row=0, column=1, sticky="w", padx=5, pady=2)
        
        # Data
        ttk.Label(race_frame, text="Data:").grid(row=1, column=0, sticky="e", padx=5, pady=2)
        ttk.Entry(race_frame, textvariable=self.race_date, width=40).grid(row=1, column=1, sticky="w", padx=5, pady=2)
        
        # Dystans
        ttk.Label(race_frame, text="Dystans:").grid(row=2, column=0, sticky="e", padx=5, pady=2)
        ttk.Entry(race_frame, textvariable=self.race_distance, width=40).grid(row=2, column=1, sticky="w", padx=5, pady=2)
        
        # Miejscowość
        ttk.Label(race_frame, text="Miejscowość:").grid(row=3, column=0, sticky="e", padx=5, pady=2)
        ttk.Entry(race_frame, textvariable=self.race_location, width=40).grid(row=3, column=1, sticky="w", padx=5, pady=2)
        
        # Typ dokumentu
        ttk.Label(race_frame, text="Typ dokumentu:").grid(row=4, column=0, sticky="e", padx=5, pady=2)
        doc_type_frame = ttk.Frame(race_frame)
        doc_type_frame.grid(row=4, column=1, sticky="w", padx=5, pady=2)
        ttk.Radiobutton(doc_type_frame, text="Lista Startowa", variable=self.document_type, value="Lista Startowa").pack(side="left", padx=5)
        ttk.Radiobutton(doc_type_frame, text="Wyniki", variable=self.document_type, value="Wyniki").pack(side="left", padx=5)
        
        # Frame dla logotypów
        logo_frame = ttk.LabelFrame(self.root, text="Logotypy (opcjonalne)", padding="10")
        logo_frame.pack(fill="x", padx=10, pady=5)
        
        # Logo 1 (prawy górny róg)
        logo1_container = ttk.Frame(logo_frame)
        logo1_container.pack(fill="x", pady=2)
        ttk.Label(logo1_container, text="Logo 1 (prawy):").pack(side="left", padx=5)
        ttk.Entry(logo1_container, textvariable=self.logo_path, state='readonly', width=30).pack(side="left", padx=5)
        ttk.Button(logo1_container, text="Wybierz", command=self.select_logo).pack(side="left", padx=2)
        ttk.Button(logo1_container, text="Wyczyść", command=lambda: self.logo_path.set("")).pack(side="left")
        
        # Logo 2 (środkowy górny róg)
        logo2_container = ttk.Frame(logo_frame)
        logo2_container.pack(fill="x", pady=2)
        ttk.Label(logo2_container, text="Logo 2 (środek):").pack(side="left", padx=5)
        ttk.Entry(logo2_container, textvariable=self.logo1_path, state='readonly', width=30).pack(side="left", padx=5)
        ttk.Button(logo2_container, text="Wybierz", command=self.select_logo1).pack(side="left", padx=2)
        ttk.Button(logo2_container, text="Wyczyść", command=lambda: self.logo1_path.set("")).pack(side="left")
        
        # Informacja o logotypach
        info_frame = ttk.Frame(logo_frame)
        info_frame.pack(fill="x", pady=5)
        info_label = ttk.Label(info_frame, text="💡 Logo 1 pojawi się w prawym górnym rogu i w stopce\n    Logo 2 pojawi się obok Logo 1 w nagłówku", 
                               foreground="gray", font=("Arial", 8))
        info_label.pack()
        
        # Przycisk generowania PDF
        ttk.Button(self.root, text="GENERUJ PDF - INTELIGENTNY LAYOUT", 
                  command=self.generate_pdf, style="Accent.TButton").pack(pady=15)
        
        # Status bar
        self.status_var = tk.StringVar(value="Gotowy do generowania PDF")
        status_bar = ttk.Label(self.root, textvariable=self.status_var, relief=tk.SUNKEN, anchor=tk.W)
        status_bar.pack(side=tk.BOTTOM, fill=tk.X)
        
    def select_csv(self):
        filename = filedialog.askopenfilename(
            title="Wybierz plik CSV",
            filetypes=[("CSV files", "*.csv"), ("All files", "*.*")]
        )
        if filename:
            self.csv_path.set(filename)
            self.status_var.set(f"Wybrano: {os.path.basename(filename)}")
    
    def select_logo(self):
        filename = filedialog.askopenfilename(
            title="Wybierz Logo 1 (prawy górny róg)",
            filetypes=[("Image files", "*.png *.jpg *.jpeg *.gif *.bmp"), ("All files", "*.*")]
        )
        if filename:
            self.logo_path.set(filename)
            self.status_var.set(f"Logo 1: {os.path.basename(filename)}")
    
    def select_logo1(self):
        filename = filedialog.askopenfilename(
            title="Wybierz Logo 2 (środkowy górny róg)",
            filetypes=[("Image files", "*.png *.jpg *.jpeg *.gif *.bmp"), ("All files", "*.*")]
        )
        if filename:
            self.logo1_path.set(filename)
            self.status_var.set(f"Logo 2: {os.path.basename(filename)}")
            
    def validate_csv_file(self, filepath):
        """Waliduje plik CSV przed przetwarzaniem"""
        encodings_to_try = ['utf-8', 'cp1250', 'iso-8859-2', 'windows-1250']
        
        for encoding in encodings_to_try:
            try:
                with open(filepath, 'r', encoding=encoding) as f:
                    # Sprawdź czy plik nie jest pusty
                    content = f.read().strip()
                    if not content:
                        raise ValueError("Plik CSV jest pusty")
                    
                    # Wróć na początek pliku
                    f.seek(0)
                    csv_reader = csv.reader(f, delimiter=';')
                    
                    # Sprawdź nagłówki
                    try:
                        headers = next(csv_reader)
                        if len(headers) == 1:
                            f.seek(0)
                            csv_reader = csv.reader(f, delimiter=',')
                            headers = next(csv_reader)
                            
                        if not headers or all(not h.strip() for h in headers):
                            continue
                            
                    except StopIteration:
                        continue
                    
                    # Sprawdź czy są jakiekolwiek wiersze z danymi
                    rows = list(csv_reader)
                    rows = [row for row in rows if any(cell.strip() for cell in row)]
                    
                    if not rows:
                        continue
                    
                    print(f"Plik wczytany z kodowaniem: {encoding}")
                    print(f"Nagłówki: {headers}")
                    print(f"Liczba wierszy: {len(rows)}")
                    
                    return headers, rows
                    
            except Exception as e:
                print(f"Błąd z kodowaniem {encoding}: {e}")
                continue
        
        raise ValueError("Nie można odczytać pliku CSV. Sprawdź format i kodowanie pliku.")
            
    def generate_pdf(self):
        if not self.csv_path.get():
            messagebox.showerror("Błąd", "Wybierz plik CSV!")
            return
            
        if not all([self.race_name.get(), self.race_date.get(), 
                   self.race_distance.get(), self.race_location.get()]):
            messagebox.showerror("Błąd", "Wypełnij wszystkie pola!")
            return
        
        # Wybór lokalizacji zapisu PDF
        race_name_clean = self.race_name.get().replace(" ", "_")
        distance_clean = self.race_distance.get().replace(" ", "")
        doc_type_clean = self.document_type.get().replace(" ", "_")
        default_filename = f"{doc_type_clean}_{race_name_clean}_{distance_clean}_ADAPTIVE.pdf"
        
        pdf_filename = filedialog.asksaveasfilename(
            title="Zapisz PDF jako",
            defaultextension=".pdf",
            filetypes=[("PDF files", "*.pdf"), ("All files", "*.*")],
            initialfile=default_filename
        )
        
        if not pdf_filename:
            return  # Użytkownik anulował
            
        try:
            self.status_var.set("Generowanie PDF...")
            self.root.update()
            
            # Waliduj plik CSV
            headers, rows = self.validate_csv_file(self.csv_path.get())
            self.create_pdf(headers, rows, pdf_filename)
            
            self.status_var.set(f"PDF wygenerowany: {os.path.basename(pdf_filename)}")
            messagebox.showinfo("Sukces", f"PDF wygenerowany pomyślnie!\n\nZapisano: {pdf_filename}")
        except ValueError as e:
            self.status_var.set("Błąd podczas generowania")
            messagebox.showerror("Błąd", str(e))
        except Exception as e:
            self.status_var.set("Błąd podczas generowania")
            messagebox.showerror("Błąd", f"Wystąpił błąd: {str(e)}")
    
    def analyze_csv_content(self, headers, rows):
        """Analizuje zawartość CSV i zwraca statystyki dla każdej kolumny"""
        column_stats = []
        
        for col_idx, header in enumerate(headers):
            values = []
            for row in rows:
                if col_idx < len(row):
                    cell_value = str(row[col_idx]).strip()
                    values.append(cell_value)
                else:
                    values.append("")
            
            non_empty_values = [val for val in values if val]
            
            if non_empty_values:
                lengths = [len(val) for val in non_empty_values]
                avg_length = sum(lengths) / len(lengths)
                max_length = max(lengths)
                min_length = min(lengths)
                median_length = sorted(lengths)[len(lengths)//2] if lengths else 0
            else:
                avg_length = max_length = min_length = median_length = 0
            
            content_type = self.analyze_content_type(header, non_empty_values)
            needs_wrapping = max_length > 25 and avg_length > 15
            
            column_stats.append({
                'header': header,
                'avg_length': avg_length,
                'max_length': max_length,
                'min_length': min_length,
                'median_length': median_length,
                'content_type': content_type,
                'needs_wrapping': needs_wrapping,
                'sample_long': max(non_empty_values, key=len) if non_empty_values else "",
                'unique_values': len(set(non_empty_values)) if non_empty_values else 0
            })
        
        return column_stats
    
    def analyze_content_type(self, header, values):
        """Określa typ treści w kolumnie na podstawie nagłówka i wartości"""
        if not values:
            return 'empty'
            
        header_lower = header.lower().strip()
        
        if any(keyword in header_lower for keyword in ['mce', 'miejsce', 'pozycja']):
            return 'position'
        elif any(keyword in header_lower for keyword in ['imię', 'imie', 'nazwisko', 'name', 'zawodnik']):
            return 'name'
        elif any(keyword in header_lower for keyword in ['miejscowość', 'miasto', 'city']):
            return 'location'
        elif any(keyword in header_lower for keyword in ['drużyna', 'klub', 'team', 'reprezentuje']):
            return 'team'
        elif any(keyword in header_lower for keyword in ['kat', 'kategoria', 'category']):
            return 'category'
        elif any(keyword in header_lower for keyword in ['czas', 'time']):
            return 'time'
        elif any(keyword in header_lower for keyword in ['nr', 'numer', 'number', 'start']):
            return 'number'
        elif any(keyword in header_lower for keyword in ['rodzic', 'uczeń', 'uczen', 'student']):
            return 'boolean'
        
        sample_values = values[:min(10, len(values))]
        
        if sample_values:
            boolean_values = {'tak', 'nie', 'yes', 'no', 'true', 'false', '1', '0'}
            if all(v.lower() in boolean_values for v in sample_values):
                return 'boolean'
            
            if all(v.isdigit() for v in sample_values):
                return 'number'
            
            time_pattern = re.compile(r'^\d{1,2}:\d{2}:\d{2}$')
            if all(time_pattern.match(v) for v in sample_values):
                return 'time'
            
            avg_len = sum(len(v) for v in sample_values) / len(sample_values)
            if avg_len > 20:
                return 'long_text'
            elif avg_len < 5:
                return 'short_text'
            else:
                return 'medium_text'
        
        return 'unknown'
    
    def calculate_optimal_layout(self, column_stats, available_width):
        """Oblicza optymalny layout tabeli na podstawie analizy treści"""
        total_columns = len(column_stats)
        
        if total_columns == 0:
            return []
        
        base_widths = []
        priority_weights = []
        
        for stats in column_stats:
            content_type = stats['content_type']
            max_len = stats['max_length']
            avg_len = stats['avg_length']
            unique_values = stats['unique_values']
            
            if content_type == 'position':
                base_width = max(25, 25 + (max_len * 2))
                priority = 1
            elif content_type == 'name':
                base_width = max(80, 90 + (avg_len * 2))
                priority = 4
            elif content_type == 'location':
                base_width = max(70, 80 + (avg_len * 2))
                priority = 3
            elif content_type == 'team':
                if stats['needs_wrapping']:
                    base_width = max(120, 140 + (avg_len * 1.2))
                else:
                    base_width = max(100, 120 + (max_len * 2))
                priority = 5
            elif content_type == 'category':
                base_width = max(40, 50 + (avg_len * 2))
                priority = 2
            elif content_type == 'time':
                base_width = 55
                priority = 1
            elif content_type == 'number':
                base_width = max(25, 30 + (max_len * 3))
                priority = 1
            elif content_type == 'boolean':
                fill_ratio = unique_values / max(1, len(column_stats))
                if fill_ratio > 0.7:
                    base_width = 40
                    priority = 2
                else:
                    base_width = 30
                    priority = 1
            elif content_type == 'long_text':
                if stats['needs_wrapping']:
                    base_width = max(100, 120 + (avg_len * 1.0))
                    priority = 4
                else:
                    base_width = max(80, 100 + (max_len * 1.5))
                    priority = 3
            elif content_type == 'empty':
                base_width = 25
                priority = 0
            else:
                base_width = max(40, 50 + (avg_len * 2))
                priority = 2
            
            base_width = min(base_width, 180)
            base_width = max(base_width, 25)
            
            base_widths.append(base_width)
            priority_weights.append(priority)
        
        total_base_width = sum(base_widths)
        
        if total_base_width > available_width:
            scale = available_width / total_base_width
            optimized_widths = [w * scale for w in base_widths]
        else:
            extra_space = available_width - total_base_width
            total_priority = sum(priority_weights)
            
            if total_priority > 0:
                optimized_widths = []
                for i, (base_width, priority) in enumerate(zip(base_widths, priority_weights)):
                    extra_for_column = extra_space * (priority / total_priority)
                    optimized_widths.append(base_width + extra_for_column)
            else:
                extra_per_column = extra_space / total_columns
                optimized_widths = [w + extra_per_column for w in base_widths]
        
        return optimized_widths
    
    def calculate_optimal_font_size(self, column_stats, column_widths):
        """Oblicza optymalny rozmiar czcionki na podstawie szerokości kolumn i treści"""
        if not column_widths or not column_stats:
            return 8
        
        content_density = []
        for i, stats in enumerate(column_stats):
            if i < len(column_widths):
                width = column_widths[i]
                avg_len = stats['avg_length']
                if avg_len > 0:
                    density = avg_len / width
                    content_density.append(density)
        
        if not content_density:
            return 8
        
        max_density = max(content_density)
        avg_density = sum(content_density) / len(content_density)
        
        if max_density < 0.1:
            font_size = 9
        elif max_density < 0.15:
            font_size = 8
        elif max_density < 0.2:
            font_size = 7
        elif max_density < 0.25:
            font_size = 6
        else:
            font_size = 5
        
        if avg_density < 0.12 and font_size < 8:
            font_size += 1
        
        font_size = max(6, min(9, font_size))
        
        return font_size
    
    def smart_text_wrapping(self, text, max_chars_per_line, content_type):
        """Inteligentne zawijanie tekstu na podstawie typu treści"""
        if len(text) <= max_chars_per_line:
            return text
        
        if content_type == 'team':
            words = text.split()
            if len(words) <= 2:
                return text
            
            mid = len(words) // 2
            line1 = " ".join(words[:mid])
            line2 = " ".join(words[mid:])
            
            if abs(len(line1) - len(line2)) < len(text) * 0.3:
                return f"{line1}<br/>{line2}"
        
        elif content_type in ['name', 'location']:
            if len(text) > max_chars_per_line * 1.5:
                words = text.split()
                if len(words) > 1:
                    mid = len(words) // 2
                    return f"{' '.join(words[:mid])}<br/>{' '.join(words[mid:])}"
        
        words = text.split()
        if len(words) > 1:
            target_pos = len(text) // 2
            best_split = 0
            best_distance = float('inf')
            
            current_pos = 0
            for i, word in enumerate(words[:-1]):
                current_pos += len(word) + 1
                distance = abs(current_pos - target_pos)
                if distance < best_distance:
                    best_distance = distance
                    best_split = i + 1
            
            if best_split > 0:
                line1 = " ".join(words[:best_split])
                line2 = " ".join(words[best_split:])
                return f"{line1}<br/>{line2}"
        
        return text
    
    def create_pdf(self, headers, rows, pdf_file):
        # Rejestracja czcionki z obsługą polskich znaków
        try:
            pdfmetrics.registerFont(TTFont('DejaVu', 'DejaVuSans.ttf'))
            font_name = 'DejaVu'
            print("Używam czcionki DejaVu z obsługą polskich znaków")
        except:
            try:
                import platform
                if platform.system() == "Windows":
                    pdfmetrics.registerFont(TTFont('Arial', 'arial.ttf'))
                    font_name = 'Arial'
                    print("Używam czcionki Arial")
                else:
                    font_name = 'Helvetica'
                    print("Używam czcionki Helvetica (może nie obsługiwać polskich znaków)")
            except:
                font_name = 'Helvetica'
                print("Ostrzeżenie: Używam domyślnej czcionki Helvetica")
        
        # Wyczyść dane
        clean_rows = []
        for row in rows:
            if any(str(cell).strip() for cell in row):
                clean_row = []
                for i in range(len(headers)):
                    if i < len(row):
                        clean_row.append(str(row[i]).strip())
                    else:
                        clean_row.append("")
                clean_rows.append(clean_row)
        
        rows = clean_rows
        print(f"Oczyszczone wiersze: {len(rows)}")
        
        # Analizuj zawartość CSV
        column_stats = self.analyze_csv_content(headers, rows)
        
        print("\n=== ANALIZA CSV ===")
        for i, stats in enumerate(column_stats):
            print(f"Kolumna {i}: {stats['header']}")
            print(f"  Typ: {stats['content_type']}")
            print(f"  Długość: avg={stats['avg_length']:.1f}, max={stats['max_length']}")
            print(f"  Zawijanie: {'TAK' if stats['needs_wrapping'] else 'NIE'}")
            if stats['sample_long']:
                print(f"  Przykład: '{stats['sample_long'][:50]}{'...' if len(stats['sample_long']) > 50 else ''}'")
            print()
        
        # Określ orientację
        use_landscape = len(headers) > 6 or any(stats['max_length'] > 30 for stats in column_stats)
        
        # Tworzenie dokumentu PDF
        page_width, page_height = landscape(A4) if use_landscape else A4
        
        doc = SimpleDocTemplate(
            pdf_file,
            pagesize=(page_width, page_height),
            rightMargin=5 * mm,
            leftMargin=5 * mm,
            topMargin=28 * mm,
            bottomMargin=15 * mm
        )
        
        # Oblicz optymalne szerokości kolumn
        column_widths = self.calculate_optimal_layout(column_stats, doc.width)
        
        # Oblicz optymalny rozmiar czcionki
        font_size = self.calculate_optimal_font_size(column_stats, column_widths)
        
        print(f"=== LAYOUT ===")
        print(f"Orientacja: {'Landscape' if use_landscape else 'Portrait'}")
        print(f"Rozmiar czcionki: {font_size}pt")
        print(f"Szerokości kolumn: {[f'{w:.1f}' for w in column_widths]}")
        print(f"Całkowita szerokość: {sum(column_widths):.1f} / {doc.width:.1f}")
        
        # Przygotuj dane tabeli
        table_data = []
        
        # Nagłówki
        headers_row = []
        for i, header in enumerate(headers):
            if " " in header and len(header) > 10:
                parts = header.split(" ", 1)
                header_text = f"{parts[0]}<br/>{parts[1]}"
            else:
                header_text = header
                
            header_style = ParagraphStyle(
                name='Header',
                fontName=font_name,
                fontSize=font_size,
                leading=font_size + 1,
                alignment=1,
                textColor=colors.whitesmoke
            )
            headers_row.append(Paragraph(header_text, header_style))
        
        table_data.append(headers_row)
        
        # Wiersze danych
        for row_idx, row in enumerate(rows):
            row_style = ParagraphStyle(
                name=f'Row{row_idx}',
                fontName=font_name,
                fontSize=font_size,
                leading=font_size + 1,
                alignment=1
            )
            
            row_cells = []
            for col_idx, cell in enumerate(row):
                if col_idx < len(headers):
                    cell_text = str(cell).strip() if cell is not None else ""
                    
                    if col_idx < len(column_stats):
                        stats = column_stats[col_idx]
                        
                        if stats['content_type'] == 'boolean':
                            if cell_text.lower() in ['tak', 'yes', 'true', '1']:
                                cell_text = "Tak"
                            elif cell_text.lower() in ['nie', 'no', 'false', '0']:
                                cell_text = "Nie"
                            else:
                                cell_text = "-"
                        
                        width = column_widths[col_idx] if col_idx < len(column_widths) else 50
                        chars_per_point = 0.6
                        max_chars = int(width * chars_per_point)
                        
                        if len(cell_text) > max_chars and stats['needs_wrapping']:
                            cell_text = self.smart_text_wrapping(
                                cell_text, max_chars, stats['content_type']
                            )
                    
                    row_cells.append(Paragraph(cell_text, row_style))
                else:
                    row_cells.append(Paragraph("", row_style))
            
            while len(row_cells) < len(headers):
                row_cells.append(Paragraph("", row_style))
            
            table_data.append(row_cells)
        
        # Tworzenie tabeli
        table = Table(table_data, colWidths=column_widths, repeatRows=1)
        
        padding_size = max(2, font_size // 3)
        
        table_style = TableStyle([
            ('BACKGROUND', (0, 0), (-1, 0), colors.HexColor('#FF6600')),
            ('TEXTCOLOR', (0, 0), (-1, 0), colors.whitesmoke),
            ('ALIGN', (0, 0), (-1, -1), 'CENTER'),
            ('VALIGN', (0, 0), (-1, -1), 'MIDDLE'),
            ('FONTNAME', (0, 0), (-1, -1), font_name),
            ('TOPPADDING', (0, 0), (-1, -1), padding_size),
            ('BOTTOMPADDING', (0, 0), (-1, -1), padding_size),
            ('LEFTPADDING', (0, 0), (-1, -1), padding_size),
            ('RIGHTPADDING', (0, 0), (-1, -1), padding_size),
            ('LINEBELOW', (0, 0), (-1, 0), 1, colors.black),
            ('LINEABOVE', (0, 1), (-1, -1), 0.5, colors.grey),
            ('LINEBELOW', (0, 0), (-1, -1), 0.5, colors.grey),
            ('LINEABOVE', (0, 0), (-1, 0), 1, colors.black),
            ('LINEBELOW', (0, -1), (-1, -1), 1, colors.black),
        ])
        
        for i in range(1, len(rows) + 1, 2):
            table_style.add('BACKGROUND', (0, i), (-1, i), colors.lightgrey)
        
        table.setStyle(table_style)
        
        # Budowanie dokumentu
        doc.build([table], onFirstPage=self.add_header_footer, onLaterPages=self.add_header_footer)
    
    def add_header_footer(self, canvas, doc):
        """Dodaje nagłówek i stopkę z wybranymi logotypami"""
        canvas.saveState()
        
        title_color = colors.HexColor('#FF6600')
        subtitle_color = colors.black
        
        try:
            canvas.setFont('DejaVu', 14)
            font_name = 'DejaVu'
        except:
            try:
                canvas.setFont('Arial', 14)
                font_name = 'Arial'
            except:
                canvas.setFont('Helvetica-Bold', 14)
                font_name = 'Helvetica'
        
        canvas.setFillColor(title_color)
        
        title = self.race_name.get().upper()
        canvas.drawString(doc.leftMargin, doc.height + doc.topMargin - 15, title)
        
        try:
            canvas.setFont(font_name, 11)
        except:
            canvas.setFont('Helvetica', 11)
        
        canvas.setFillColor(subtitle_color)
        subtitle = f"{self.document_type.get()} | {self.race_distance.get()} | {self.race_location.get()} | {self.race_date.get()}"
        canvas.drawString(doc.leftMargin, doc.height + doc.topMargin - 30, subtitle)
        
        # Logo 1 (prawy górny róg) - z wybranej ścieżki
        if self.logo_path.get() and os.path.exists(self.logo_path.get()):
            try:
                logo = Image(self.logo_path.get(), width=80, height=35)
                logo.drawOn(canvas, doc.width + doc.leftMargin - 85, doc.height + doc.topMargin - 40)
                print(f"Logo 1 dodane: {self.logo_path.get()}")
            except Exception as e:
                print(f"Błąd podczas dodawania Logo 1: {e}")
        
        # Logo 2 (środkowy górny róg) - z wybranej ścieżki
        if self.logo1_path.get() and os.path.exists(self.logo1_path.get()):
            try:
                logo1 = Image(self.logo1_path.get(), width=80, height=35)
                logo1.drawOn(canvas, doc.width + doc.leftMargin - 170, doc.height + doc.topMargin - 40)
                print(f"Logo 2 dodane: {self.logo1_path.get()}")
            except Exception as e:
                print(f"Błąd podczas dodawania Logo 2: {e}")
        
        try:
            canvas.setFont(font_name, 7)
        except:
            canvas.setFont('Helvetica', 7)
        
        canvas.setFillColor(colors.black)
        
        footer_text = "Wygenerował: YO&GO Events - Twój pomiar czasu www.yogoevents.pl"
        canvas.drawString(doc.leftMargin, 15, footer_text)
        
        page_number = f"Strona {doc.page}"
        canvas.drawRightString(doc.width + doc.leftMargin, 15, page_number)
        
        # Logo w stopce (jeśli wybrane Logo 1)
        if self.logo_path.get() and os.path.exists(self.logo_path.get()):
            try:
                logo_stopka = Image(self.logo_path.get(), width=25, height=12)
                logo_stopka.drawOn(canvas, (doc.width/2 + doc.leftMargin - 12), 11)
            except Exception as e:
                print(f"Błąd podczas dodawania logo stopki: {e}")
        
        canvas.restoreState()

if __name__ == "__main__":
    root = tk.Tk()
    app = ResultsGeneratorApp(root)
    root.mainloop()