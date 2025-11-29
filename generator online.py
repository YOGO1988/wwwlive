import tkinter as tk
from tkinter import ttk, filedialog, messagebox, simpledialog
import json
import requests
import os
import sys
import threading
import time
from datetime import datetime
import re
from reportlab.lib.pagesizes import A4, landscape
from reportlab.platypus import SimpleDocTemplate, Table, TableStyle, Paragraph, Image, Spacer
from reportlab.lib import colors
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.lib.units import inch, mm

class ChronoTrackApiClient:
    """
    Klient API do komunikacji z ChronoTrack API
    """
    def __init__(self):
        # Ustaw z góry zdefiniowane dane dostępowe
        self.config = {
            'clientId': '727dae7f',
            'userId': 'lukasz@yogoevents.pl',
            'userPass': 'f2b2f3082a9d2ae5091eb5920bee538dc01bb413',
            'baseUrl': 'https://api.chronotrack.com',
            'eventId': ''
        }
        
        self.search_var = tk.StringVar()  # Zmienna do wyszukiwania
        self.filtered_athletes = []  # Lista przefiltrowanych zawodników
        # Inicjalizacja pamięci podręcznej
        self.cache = {
            'athletes': {},
            'openResults': [],
            'eventInfo': None,
            'regChoices': []  # Przechowywanie dostępnych dystansów
        }
        
        # Lista znalezionych pól custom_element
        self.custom_fields = []
        self.entries = {}
        
        # Ścieżki do plików logo
        self.logo1_path = ""
        self.logo2_path = ""
        
        print(f"Używam skonfigurowanych danych dostępowych API: {self.config['clientId']}, {self.config['userId']}")
    
    def process_custom_elements(self, athlete_data):
        custom_questions = []
    
        for key in list(athlete_data.keys()):
            # Sprawdź, czy klucz zaczyna się od "custom_element"
            if key.startswith('custom_element'):
                value = athlete_data[key]
                
                # Pomiń puste wartości i odpowiedzi "Tak"
                if value is not None and value != '' and value != 'Tak':
                    # Wyciągnij ID pytania z nazwy pola
                    question_id = key.replace('custom_element', '')
                    
                    # Dodaj do listy pytań
                    custom_questions.append({
                        'id': question_id,
                        'field': key,
                        'question': f'Pytanie {question_id}',
                        'answer': value
                    })
                
                # Jeśli odpowiedź to "Tak", zapisz ją w custom_2
                if value == 'Tak':
                    athlete_data['custom_2'] = value
        
        print(f"Znaleziono {len(custom_questions)} pytań customowych")
        return custom_questions
    
    def build_api_url(self, endpoint, params=None):
        """Tworzy URL zapytania do API"""
        formatted_endpoint = endpoint if endpoint.endswith('/') else f"{endpoint}/"
        
        # Parametry uwierzytelniające
        auth_params = {
            'format': 'json',
            'client_id': self.config['clientId'],
            'user_id': self.config['userId'],
            'user_pass': self.config['userPass']
        }
        
        # Połącz z innymi parametrami
        if params:
            auth_params.update(params)
            
        # Zbuduj URL zapytania
        query_string = '&'.join([f"{key}={requests.utils.quote(str(value))}" for key, value in auth_params.items()])
        return f"{self.config['baseUrl']}{formatted_endpoint}?{query_string}"
    
    def make_api_request(self, endpoint, params=None):
        """Wykonuje zapytanie do API"""
        url = self.build_api_url(endpoint, params)
        print(f"Zapytanie do API: {url}")
        
        try:
            response = requests.get(url)
            response.raise_for_status()
            return response.json()
        except requests.exceptions.RequestException as e:
            print(f"Błąd podczas zapytania API: {e}")
            return None
    
    def fetch_event_info(self):
        """Pobiera informacje o wydarzeniu"""
        endpoint = f"/api/event/{self.config['eventId']}"
        
        response = self.make_api_request(endpoint)
        if not response or 'event' not in response:
            print("Błąd: Brak obiektu event w odpowiedzi API")
            return None
        
        event_data = response['event']
        
        if 'event_name' not in event_data:
            print("Błąd: Brak event_name w danych wydarzenia")
            return None
        
        event_info = {
            'event_id': event_data.get('event_id', ''),
            'event_name': event_data.get('event_name', ''),
            'event_date': event_data.get('event_start_time', ''),
            'formatted_date': self.format_event_date(event_data.get('event_start_time', '')),
            'timezone': event_data.get('location_time_zone', ''),
            'status': 'active' if event_data.get('event_is_published') == '1' else 'inactive',
            'location': f"{event_data.get('location_city', '')}, {event_data.get('location_country', '')}"
        }
        
        self.cache['eventInfo'] = event_info
        
        # Pobierz dostępne dystanse
        self.fetch_reg_choices()
        
        return event_info
    
    def fetch_reg_choices(self):
        """Pobiera dostępne dystanse (reg_choice) dla wydarzenia"""
        # Poprawiony endpoint z myślnikiem zamiast podkreślnika
        endpoint = f"/api/event/{self.config['eventId']}/reg-choice"
        
        try:
            # Dodaj parametry do zapytania
            params = {
                'page': 1,
                'size': 100,  # Zwiększona liczba wyników na stronę
                'include_more_info': True
            }
            
            response = self.make_api_request(endpoint, params)
            
            # Drukuj pełną odpowiedź dla celów diagnostycznych
            print("Pełna odpowiedź API:")
            print(response)
            
            choices = []
            
            if response:
                # Sprawdź różne możliwe formaty odpowiedzi z API
                reg_choices = []
                
                # W zależności od formatu odpowiedzi, wybierz odpowiednie pole
                if 'reg_choices' in response:
                    print("Znaleziono pole 'reg_choices'")
                    reg_choices = response['reg_choices']
                elif 'reg_choice' in response:
                    print("Znaleziono pole 'reg_choice'")
                    reg_choices = response['reg_choice']
                elif 'choices' in response:
                    print("Znaleziono pole 'choices'")
                    reg_choices = response['choices']
                else:
                    print("Nie znaleziono standardowych pól w odpowiedzi API")
                    
                    # Jeśli odpowiedź jest listą, użyj jej bezpośrednio
                    if isinstance(response, list):
                        reg_choices = response
                    # Jeśli to obiekt, sprawdź czy ma listę obiektów wewnątrz
                    elif isinstance(response, dict):
                        for key, value in response.items():
                            if isinstance(value, list) and len(value) > 0:
                                reg_choices = value
                                print(f"Znaleziono listę w polu '{key}'")
                                break
                
                # Przetwórz znalezione wybory
                for choice in reg_choices:
                    choice_id = None
                    choice_name = None
                    
                    # Znajdź ID wyboru
                    if 'reg_choice_id' in choice:
                        choice_id = choice['reg_choice_id']
                    elif 'id' in choice:
                        choice_id = choice['id']
                    elif 'racer_id' in choice:
                        choice_id = choice['racer_id']
                    
                    # Znajdź nazwę wyboru
                    if 'reg_choice_name' in choice:
                        choice_name = choice['reg_choice_name']
                    elif 'name' in choice:
                        choice_name = choice['name']
                    elif 'racer_name' in choice:
                        choice_name = choice['racer_name']
                    
                    # Jeśli mamy zarówno ID jak i nazwę, dodaj do listy wyborów
                    if choice_id and choice_name:
                        print(f"Dodaję dystans: {choice_name} (ID: {choice_id})")
                        choices.append({
                            'id': choice_id,
                            'name': choice_name
                        })
                        
                if not choices:
                    print("Nie wykryto dystansów w odpowiedzi API, próba analizy odpowiedzi...")
                    
                    # Bezpośrednio przeszukaj odpowiedź w poszukiwaniu pól reg_choice_name
                    if isinstance(response, dict):
                        for key, value in response.items():
                            if isinstance(value, list):
                                for item in value:
                                    if isinstance(item, dict):
                                        if 'reg_choice_name' in item and 'reg_choice_id' in item:
                                            choice_id = item['reg_choice_id']
                                            choice_name = item['reg_choice_name']
                                            print(f"Znaleziono dystans w zagnieżdżonej strukturze: {choice_name} (ID: {choice_id})")
                                            choices.append({
                                                'id': choice_id,
                                                'name': choice_name
                                            })
            else:
                print("Brak odpowiedzi z API lub błąd podczas zapytania")
                
                # Jako rezerwowe podejście, próbujemy wykryć dystanse z wyników
                print("Próba wykrycia dystansów z wyników OPEN...")
                open_results = self.fetch_open_results(True)
                
                # Przygotuj unikalną listę dystansów z wyników
                unique_distances = {}
                for result in open_results:
                    distance = result.get('race_distance', result.get('results_race_name', ''))
                    choice_id = result.get('reg_choice_id', result.get('results_reg_choice', ''))
                    if distance and choice_id and choice_id not in unique_distances:
                        unique_distances[choice_id] = distance
                
                # Przekształć na listę słowników
                choices = [{'id': k, 'name': v} for k, v in unique_distances.items()]
                
                print(f"Wykryto {len(choices)} dystansów z wyników: {[choice['name'] for choice in choices]}")
            
            # Zapisz dystanse w cache
            self.cache['regChoices'] = choices
            print(f"Zapisano {len(choices)} dostępnych dystansów: {[choice['name'] for choice in choices]}")
            
            return choices
        except Exception as e:
            print(f"Błąd podczas pobierania dystansów: {e}")
            import traceback
            traceback.print_exc()
            self.cache['regChoices'] = []
            return []
    
    def format_event_date(self, timestamp):
        """Formatuje datę wydarzenia w czytelnym formacie"""
        if not timestamp:
            return '-'
        
        try:
            # Timestamp może być stringiem lub liczbą
            date = datetime.fromtimestamp(int(timestamp))
            
            # Formatuj datę jako DD.MM.YYYY
            return date.strftime('%d.%m.%Y')
        except Exception as e:
            print(f'Błąd formatowania daty: {e}')
            return str(timestamp)
    def format_time(self, time_string):
        """Formatuje czas w formacie HH:MM:SS"""
        if not time_string:
            return '-'
        
        # Sprawdź format czasu - może być w różnych formatach
        if ':' in time_string:
            # Format HH:MM:SS.MS
            time_parts = time_string.split(':')
            hours = int(time_parts[0])
            minutes = int(time_parts[1])
            
            # Sekundy mogą zawierać milisekundy
            if len(time_parts) > 2:
                seconds_part = time_parts[2].split('.') if '.' in time_parts[2] else [time_parts[2]]
                seconds = int(seconds_part[0])
                milliseconds = int(seconds_part[1]) if len(seconds_part) > 1 else 0
                
                # Zaokrąglij w górę, jeśli są milisekundy
                if milliseconds > 0:
                    seconds += 1
                
                # Popraw przepełnienia
                if seconds >= 60:
                    minutes += 1
                    seconds -= 60
                
                if minutes >= 60:
                    hours += 1
                    minutes -= 60
            else:
                seconds = 0
            
            # Formatuj wynik
            return f"{hours:02d}:{minutes:02d}:{seconds:02d}"
        elif time_string.isdigit():
            # Format sekundowy
            total_seconds = int(time_string)
            hours = total_seconds // 3600
            minutes = (total_seconds % 3600) // 60
            seconds = total_seconds % 60
            
            return f"{hours:02d}:{minutes:02d}:{seconds:02d}"
        
        # Jeśli format nie został rozpoznany, zwróć oryginalny string
        return time_string
    
    def format_pace(self, pace_string):
        """Formatuje tempo w formacie MM:SS na kilometr"""
        if not pace_string:
            return '-'
        
        # Usuń białe znaki na początku i końcu
        pace_string = str(pace_string).strip()
        
        # Jeśli tempo już jest w formacie MM:SS lub M:SS, zwróć bez zmian
        if ':' in pace_string:
            parts = pace_string.split(':')
            
            if len(parts) == 2:
                try:
                    # Format MM:SS lub M:SS
                    minutes = int(parts[0])
                    seconds = int(float(parts[1]))  # float na wypadek dziesiętnych sekund
                    
                    # Sprawdź czy wartości są rozsądne dla tempa (0-20 min/km)
                    if 0 <= minutes <= 20 and 0 <= seconds <= 59:
                        return f"{minutes}:{seconds:02d}"
                        
                except (ValueError, IndexError):
                    pass
            
            elif len(parts) == 3:
                try:
                    # Format HH:MM:SS - konwertuj na MM:SS
                    hours = int(parts[0])
                    minutes = int(parts[1])
                    seconds = int(float(parts[2]))
                    
                    # Konwertuj godziny na minuty
                    total_minutes = hours * 60 + minutes
                    
                    # Sprawdź czy to rozsądne tempo (do 30 min/km)
                    if total_minutes <= 30 and 0 <= seconds <= 59:
                        return f"{total_minutes}:{seconds:02d}"
                        
                except (ValueError, IndexError):
                    pass
        
        # Sprawdź czy to liczba (sekundy na kilometr)
        try:
            total_seconds = float(pace_string)
            
            # Konwertuj sekundy na minuty:sekundy
            if total_seconds > 0:
                minutes = int(total_seconds // 60)
                seconds = int(total_seconds % 60)
                
                # Sprawdź czy to rozsądne tempo
                if minutes <= 30:
                    return f"{minutes}:{seconds:02d}"
                    
        except (ValueError, TypeError):
            pass
        
        # Sprawdź czy to tempo w formacie dziesiętnym (np. 5.5 = 5:30)
        try:
            decimal_pace = float(pace_string)
            if 0 < decimal_pace <= 30:  # Rozsądny zakres dla tempa w min/km
                minutes = int(decimal_pace)
                seconds = int((decimal_pace - minutes) * 60)
                return f"{minutes}:{seconds:02d}"
        except (ValueError, TypeError):
            pass
        
        # Jeśli nic nie pasuje, zwróć oryginalną wartość lub placeholder
        if pace_string and pace_string != '0':
            return str(pace_string)
        else:
            return '-'
    
    def fetch_all_entries(self):
        """Pobiera wszystkie wpisy zawodników"""
        print('Pobieranie wszystkich wpisów zawodników...')
        
        entries = {}
        
        try:
            page = 1
            has_more_pages = True
            max_pages = 50  # Zwiększony limit stron
            
            while has_more_pages and page <= max_pages:
                print(f"Pobieranie strony {page} wpisów zawodników...")
                
                endpoint = f"/api/event/{self.config['eventId']}/entry"
                params = {
                    'page': page,
                    'size': 50,  # Standardowy rozmiar strony
                    'include_test_entries': True,
                    'elide_json': False,
                    'contact_details': True,
                    'include_all_fields': True,
                    'need_athlete_birthdate': True,  # Dodany parametr dla daty urodzenia
                    'format': 'json',
                    'client_id': self.config['clientId'],
                    'user_id': self.config['userId'],
                    'user_pass': self.config['userPass']
                }
                
                # Bezpośrednie zapytanie API zamiast przez make_api_request
                url = f"{self.config['baseUrl']}{endpoint}/"
                query_string = '&'.join([f"{key}={requests.utils.quote(str(value))}" for key, value in params.items()])
                full_url = f"{url}?{query_string}"
                
                print(f"Zapytanie do API: {full_url}")
                response = requests.get(full_url)
                
                if response.status_code == 200:
                    response_data = response.json()
                    
                    # Sprawdź, czy API zwróciło dane w formacie event_entry
                    if response_data and 'event_entry' in response_data and response_data['event_entry']:
                        found_entries = response_data['event_entry']
                    # Alternatywnie, sprawdź, czy zwróciło dane w formacie entries
                    elif response_data and 'entries' in response_data and response_data['entries']:
                        found_entries = response_data['entries']
                    else:
                        found_entries = []
                    
                    if found_entries:
                        print(f"Znaleziono {len(found_entries)} wpisów na stronie {page}")
                        
                        # Dodaj wpisy do słownika, indeksując po numerze BIB
                        for entry in found_entries:
                            if entry.get('entry_bib'):
                                entries[entry['entry_bib']] = entry
                                
                                # Wyciągnij wszystkie dane custom_element do osobnego słownika
                                custom_elements = {}
                                for key in list(entry.keys()):
                                    if key.startswith('custom_element') and entry[key]:
                                        custom_elements[key] = entry[key]
                                
                                # Sprawdź pola custom_element dla klubu (bezpieczna iteracja)
                                for key, value in custom_elements.items():
                                    # Dodaj do listy wykrytych pól custom
                                    if key not in self.custom_fields:
                                        self.custom_fields.append(key)
                                    
                                    # Sprawdź czy to może być klub (custom_element2602xx)
                                    if key.startswith('custom_element'):
                                        # Zapisz jako możliwy klub
                                        entry['possible_club'] = value
                                        print(f"Znaleziono możliwy klub w polu {key}: {value}")
                                
                                # Sprawdź czy zawodnik ma pole location_city
                                if 'location_city' in entry and entry['location_city']:
                                    # Zapisz jako miasto
                                    entry['athlete_city'] = entry['location_city']
                                    print(f"Znaleziono miasto w polu location_city: {entry['location_city']}")
                                
                                # Sprawdź czy zawodnik ma pole race_distance lub race_name
                                if 'race_distance' in entry and entry['race_distance']:
                                    # Zapisz jako dystans
                                    entry['race_name'] = entry['race_distance']
                                    print(f"Znaleziono dystans w polu race_distance: {entry['race_distance']}")
                                elif 'race_name' in entry and entry['race_name']:
                                    # Zapisz jako dystans
                                    entry['race_distance'] = entry['race_name']
                                    print(f"Znaleziono dystans w polu race_name: {entry['race_name']}")
                                
                                # Sprawdź dane urodzenia (wyświetl informacje debugowania)
                                if 'athlete_birthdate' in entry:
                                    print(f"Znaleziono athlete_birthdate: {entry['athlete_birthdate']}")
                                
                                if 'reg_transaction_account_birthdate' in entry:
                                    print(f"Znaleziono reg_transaction_account_birthdate: {entry['reg_transaction_account_birthdate']}")
                                
                                # Sprawdź kary (penalties)
                                if 'penalties' in entry:
                                    print(f"Znaleziono kary (penalties): {entry['penalties']}")
                                
                                # Przetwórz pola custom_element
                                custom_questions = self.process_custom_elements(entry)
                                if custom_questions:
                                    entry['custom_questions'] = custom_questions
                        
                        # Sprawdź, czy są kolejne strony
                        if response_data.get('page') and response_data.get('page_count'):
                            has_more_pages = int(response_data['page']) < int(response_data['page_count'])
                        elif len(found_entries) >= 50:  # Używamy parametru size z zapytania
                            has_more_pages = True
                        else:
                            has_more_pages = False
                        
                        page += 1
                    else:
                        has_more_pages = False
                else:
                    print(f"Błąd podczas zapytania API: {response.status_code} - {response.text}")
                    has_more_pages = False
            
            print(f"Pobrano łącznie {len(entries)} wpisów zawodników.")
            return entries
        except Exception as e:
            print(f"Błąd podczas pobierania wpisów zawodników: {e}")
            import traceback
            traceback.print_exc()
            return {}

    def fetch_results(self):
        """
        Fetch results from ChronoTrack API with pagination
        EXACT COPY from class-chronotrack-api.php lines 228-342
        """
        print(f"ChronoTrack API: Fetching results for event {self.config['eventId']}")

        # First, fetch participant entries to get city and club data
        entries_by_bib = self.fetch_all_entries()

        all_results_by_bib = {}
        page = 1
        has_more_pages = True

        # Fetch all pages of results
        while has_more_pages:
            params = {
                'format': 'json',
                'page': page,
                'per_page': 100
            }

            endpoint = f"/api/event/{self.config['eventId']}/results"
            response = self.make_api_request(endpoint, params)

            if response and 'event_results' in response and response['event_results']:
                print(f"ChronoTrack API: Page {page}: found {len(response['event_results'])} records")

                # Process results from this page
                for result in response['event_results']:
                    bib = result.get('results_bib', '')
                    if not bib:
                        continue

                    # Initialize bib entry if not exists
                    if bib not in all_results_by_bib:
                        all_results_by_bib[bib] = {
                            'main_result': None,
                            'split_times': []
                        }

                    interval_name = result.get('results_interval_name', '')

                    # Check if this is main result or split time
                    if interval_name in ['Full Course', 'Finish', ''] or not interval_name:
                        # Main result - only save if we don't have one yet
                        if all_results_by_bib[bib]['main_result'] is None:
                            all_results_by_bib[bib]['main_result'] = result
                    else:
                        # Split time
                        split_data = {
                            'interval_name': interval_name,
                            'time': result.get('results_time', ''),
                            'pace': result.get('results_pace', ''),
                            'formatted_time': self.format_time(result.get('results_time', '')),
                            'formatted_pace': self.format_pace(result.get('results_pace', ''))
                        }

                        # Check if we already have this split for this bib
                        existing = False
                        for existing_split in all_results_by_bib[bib]['split_times']:
                            if existing_split['interval_name'] == interval_name:
                                existing = True
                                break

                        if not existing:
                            all_results_by_bib[bib]['split_times'].append(split_data)

                # Check for more pages
                if 'page' in response and 'page_count' in response:
                    has_more_pages = int(response['page']) < int(response['page_count'])
                elif len(response['event_results']) >= 100:
                    has_more_pages = True
                else:
                    has_more_pages = False

                page += 1
            else:
                print('ChronoTrack API: No results on this page or invalid response')
                has_more_pages = False

        # Process collected results
        print(f"ChronoTrack API: Processing results for {len(all_results_by_bib)} athletes")

        processed_results = []
        for bib, data in all_results_by_bib.items():
            if data['main_result'] is None:
                print(f"ChronoTrack API: No main result for BIB {bib}, skipping")
                continue

            result = data['main_result']
            entry = entries_by_bib.get(bib, {})
            processed_result = self.process_single_result(result, data['split_times'], entry)
            if processed_result:
                processed_results.append(processed_result)

        return processed_results

    def process_single_result(self, result, split_times=[], entry={}):
        """
        Process single result from API
        EXACT COPY from class-chronotrack-api.php lines 348-417
        Merges result data with entry data (for city, club, etc.)
        """
        # Sort split times by time (shortest first)
        def parse_time_to_seconds(time_str):
            if not time_str or time_str == '-':
                return 999999  # For sorting purposes
            if ':' in time_str:
                parts = time_str.split(':')
                if len(parts) == 3:  # HH:MM:SS
                    return int(parts[0]) * 3600 + int(parts[1]) * 60 + int(parts[2])
                elif len(parts) == 2:  # MM:SS
                    return int(parts[0]) * 60 + int(parts[1])
            return int(time_str)

        split_times.sort(key=lambda x: parse_time_to_seconds(x.get('formatted_time', '')))

        participant_id = result.get('athlete_id', f"participant_{result.get('results_bib', '')}")

        # Extract birth year from birthdate (prefer entry data, fallback to result)
        birth_year = ''
        if entry.get('birthdate'):
            birth_year = entry['birthdate'][:4] if len(entry['birthdate']) >= 4 else ''
        elif result.get('results_birthdate'):
            birth_year = result['results_birthdate'][:4] if len(result['results_birthdate']) >= 4 else ''

        # City - prefer entry data
        city = entry.get('city', result.get('results_city', ''))

        # Club - prefer entry data
        club = entry.get('club', result.get('results_club', ''))

        # Distance - prefer entry data
        distance = entry.get('distance', result.get('results_race_name', result.get('race_distance', '')))

        return {
            'participant_id': participant_id,
            'bib_number': result.get('results_bib', ''),
            'entry_bib': result.get('results_bib', ''),
            'first_name': result.get('results_first_name', ''),
            'last_name': result.get('results_last_name', ''),
            'athlete_first_name': result.get('results_first_name', ''),
            'athlete_last_name': result.get('results_last_name', ''),
            'full_name': (result.get('results_last_name', '') + ' ' + result.get('results_first_name', '')).strip(),
            'age': result.get('results_age', 0),
            'entry_race_age': result.get('results_age', 0),
            'gender': result.get('results_sex', ''),
            'athlete_sex': result.get('results_sex', ''),
            'city': city,
            'athlete_city': city,
            'location_city': city,
            'club': club,
            'athlete_club': club,
            'birth_year': birth_year,
            'birthdate': entry.get('birthdate', result.get('results_birthdate', '')),
            'distance': distance,
            'race_name': distance,
            'race_distance': distance,
            'category': result.get('results_primary_bracket_name', ''),
            'bracket_name': result.get('results_primary_bracket_name', ''),
            'results_primary_bracket_name': result.get('results_primary_bracket_name', ''),
            'position': result.get('results_rank', 0),
            'overall_place': result.get('results_rank', 0),
            'results_rank': result.get('results_rank', 0),
            'category_position': result.get('results_division_rank', 0),
            'division_place': result.get('results_division_rank', 0),
            'results_division_rank': result.get('results_division_rank', 0),
            'category_place': result.get('results_division_rank', 0),
            'bracket_place': result.get('results_division_rank', 0),
            'gender_position': result.get('results_sex_rank', 0),
            'gender_place': result.get('results_sex_rank', 0),
            'sex_place': result.get('results_sex_rank', 0),
            'results_sex_rank': result.get('results_sex_rank', 0),
            'finish_time': self.format_time(result.get('results_gun_time', '')),
            'gun_time': self.format_time(result.get('results_gun_time', '')),
            'results_gun_time': self.format_time(result.get('results_gun_time', '')),
            'formatted_gun_time': self.format_time(result.get('results_gun_time', '')),
            'net_time': self.format_time(result.get('results_time', '')),
            'formatted_net_time': self.format_time(result.get('results_time', '')),
            'results_time': self.format_time(result.get('results_time', '')),
            'pace': self.format_pace(result.get('results_pace', '')),
            'formatted_pace': self.format_pace(result.get('results_pace', '')),
            'split_times': split_times,
            'status': result.get('results_status', 'OK'),
            'penalties': result.get('results_penalties', ''),
            'reg_choice_id': result.get('results_reg_choice', ''),
            'reg_choice_name': result.get('results_reg_choice_name', '')
        }

    def fetch_all_data(self, reg_choice_id=None):
        """Pobiera wszystkie dane z API - using new fetch_results()"""
        print('Pobieranie wszystkich danych...')

        try:
            if not self.config['eventId']:
                raise ValueError("Brak ID wydarzenia")

            # Fetch event info
            if not self.cache['eventInfo']:
                self.fetch_event_info()

            # Fetch all results using new method
            all_results = self.fetch_results()

            # Update cache
            for athlete in all_results:
                if athlete.get('bib_number'):
                    self.cache['athletes'][athlete['bib_number']] = athlete

            self.cache['openResults'] = all_results

            return {
                'open': len(all_results),
                'total': len(self.cache['athletes'])
            }
        except Exception as e:
            print(f'Błąd podczas pobierania wszystkich danych: {e}')
            import traceback
            traceback.print_exc()
            return None


class ResultsGeneratorApp:
    def __init__(self, root):
        self.root = root
        self.root.title("Generator wyników YO&GO Events")
        self.root.geometry("1200x750")
        
        # Zainicjuj klienta API
        self.api_client = ChronoTrackApiClient()
        
        # Zmienne do przechowywania danych
        self.event_id = tk.StringVar()
        self.race_name = tk.StringVar()
        self.race_date = tk.StringVar()
        self.race_distance = tk.StringVar()
        self.race_location = tk.StringVar()
        self.selected_reg_choice = tk.StringVar()
        self.loading_label_var = tk.StringVar()  # Zmienna do wyświetlania statusu pobierania
        
        # Dane zawodników
        self.athletes = []
        self.reg_choices = []
        self.current_reg_choice_id = None  # ID aktualnie wybranego dystansu
        self.current_race_name = None      # Nazwa aktualnie wybranego biegu
        self.filtered_athletes = []
        
        # Podstawowe definicje kolumn (stałe)
        self.all_columns = [
            {"id": "overall_place", "name": "Mce Open", "description": "Miejsce w klasyfikacji ogólnej", "api_options": ["overall_place", "results_rank", "place"], "selected": True},
            {"id": "entry_bib", "name": "Nr Start", "description": "Numer startowy", "api_options": ["entry_bib", "bib", "results_bib"], "selected": True},
            {"id": "full_name", "name": "Nazwisko, Imię", "description": "Nazwisko i imię zawodnika", "api_options": ["full_name", "athlete_last_name,athlete_first_name"], "selected": True},
            {"id": "athlete_city", "name": "Miejscowość", "description": "Miejscowość zawodnika", "api_options": ["athlete_city", "city", "location", "athlete_location", "custom_element_location", "custom_element_city", "location_city"], "selected": True},
            {"id": "club", "name": "Klub", "description": "Klub zawodnika", "api_options": ["club", "team", "athlete_club", "custom_element_club", "custom_element_team"], "selected": True},
            {"id": "birth_year", "name": "Rok Ur", "description": "Rok urodzenia", "api_options": ["birth_year", "birthdate", "athlete_birthdate"], "selected": True},
            {"id": "bracket_name", "name": "Kat", "description": "Kategoria wiekowa", "api_options": ["bracket_name", "category", "results_primary_bracket_name"], "selected": True},
            {"id": "division_place", "name": "Msc Kat", "description": "Miejsce w kategorii wiekowej", "api_options": ["division_place", "category_place", "bracket_place"], "selected": True},
            {"id": "gender_place", "name": "Msc M/K", "description": "Miejsce w kategorii płci", "api_options": ["gender_place", "sex_place"], "selected": True},
            {"id": "formatted_gun_time", "name": "Czas Brutto", "description": "Czas od wystrzału", "api_options": ["formatted_gun_time", "gun_time"], "selected": True},
            {"id": "formatted_pace", "name": "Tempo Min/km", "description": "Tempo biegu", "api_options": ["formatted_pace", "pace"], "selected": True},
            {"id": "formatted_net_time", "name": "Czas Netto", "description": "Czas od przekroczenia linii startu", "api_options": ["formatted_net_time", "net_time"], "selected": True},
            {"id": "penalties", "name": "Kary", "description": "Kary czasowe", "api_options": ["penalties", "results_penalties"], "selected": False},
            {"id": "formatted_split_time", "name": "Czas PK", "description": "Czas z pierwszego punktu kontrolnego", "api_options": ["formatted_split_time", "split_time"], "selected": False},
            {"id": "split_interval_name", "name": "Punkt Kontrolny", "description": "Nazwa punktu kontrolnego", "api_options": ["split_interval_name"], "selected": False}
        ]
        
        # Lista wszystkich możliwych atrybutów z API (rozszerzona)
        self.all_api_attributes = [
            "overall_place", "results_rank", "place", "entry_bib", "bib", "results_bib", 
            "full_name", "athlete_last_name", "athlete_first_name", "athlete_sex", 
            "athlete_city", "city", "location", "athlete_location", "location_city",
            "club", "team", "athlete_club", 
            "birth_year", "birthdate", "athlete_birthdate", "entry_race_age", "athlete_age",
            "bracket_name", "category", "results_primary_bracket_name", 
            "division_place", "category_place", "bracket_place", 
            "gender_place", "sex_place", "results_sex_rank",
            "formatted_gun_time", "gun_time", "results_gun_time",
            "formatted_pace", "pace", "results_pace",
            "formatted_net_time", "net_time", "results_time",
            "race_distance", "race_name", "results_race_name", "reg_choice_name",
            "athlete_country", "country", "athlete_state", "state",
            "penalties", "results_penalties", "custom_2"
            "split_time", "formatted_split_time", "split_interval"             
            # Dodane atrybuty kar i custom_2
            "split_time", "formatted_split_time", "split_interval_name", 
            "split_pace", "formatted_split_pace", "split_times"
            # Dodaj te atrybuty do istniejącej listy
            "split_1_name", "split_1_time", "split_1_formatted_time", "split_1_pace", "split_1_formatted_pace",
            "split_2_name", "split_2_time", "split_2_formatted_time", "split_2_pace", "split_2_formatted_pace",
            "split_3_name", "split_3_time", "split_3_formatted_time", "split_3_pace", "split_3_formatted_pace",
            "split_4_name", "split_4_time", "split_4_formatted_time", "split_4_pace", "split_4_formatted_pace",
            "split_5_name", "split_5_time", "split_5_formatted_time", "split_5_pace", "split_5_formatted_pace"
        ]
        
        # Aktywne kolumny (te, które zostały wybrane)
        self.active_columns = [col for col in self.all_columns if col["selected"]]
        
        # Stwórz interfejs
        self.create_widgets()
        
    def create_widgets(self):
        # Główna ramka
        main_frame = ttk.Frame(self.root, padding="10")
        main_frame.pack(fill="both", expand=True)
        
        # Ramka dla danych API
        api_frame = ttk.LabelFrame(main_frame, text="Dane API", padding="10")
        api_frame.pack(fill="x", padx=10, pady=5)
        
        # ID Wydarzenia
        ttk.Label(api_frame, text="ID Wydarzenia:").grid(row=0, column=0, sticky="e", padx=5, pady=2)
        ttk.Entry(api_frame, textvariable=self.event_id, width=20).grid(row=0, column=1, sticky="w", padx=5, pady=2)
        ttk.Button(api_frame, text="Pobierz dane", command=self.fetch_data).grid(row=0, column=2, padx=5, pady=2)
        ttk.Button(api_frame, text="Aktualizuj dane", command=self.refresh_data).grid(row=0, column=3, padx=5, pady=2)
        
        # Wybór dystansu
        ttk.Label(api_frame, text="Dystans:").grid(row=0, column=4, sticky="e", padx=5, pady=2)
        self.reg_choice_dropdown = ttk.Combobox(api_frame, textvariable=self.selected_reg_choice, width=30, state="readonly")
        self.reg_choice_dropdown.grid(row=0, column=5, sticky="w", padx=5, pady=2)
        self.reg_choice_dropdown.bind("<<ComboboxSelected>>", self.on_reg_choice_selected)
        
        # Ramka dla danych zawodów
        race_frame = ttk.LabelFrame(main_frame, text="Dane zawodów", padding="10")
        race_frame.pack(fill="x", padx=10, pady=5)
        
        # Poziomy układ dla danych zawodów
        # Nazwa biegu
        ttk.Label(race_frame, text="Nazwa biegu:").grid(row=0, column=0, sticky="e", padx=5, pady=2)
        ttk.Entry(race_frame, textvariable=self.race_name, width=25).grid(row=0, column=1, sticky="w", padx=5, pady=2)
        
        # Data
        ttk.Label(race_frame, text="Data:").grid(row=0, column=2, sticky="e", padx=5, pady=2)
        ttk.Entry(race_frame, textvariable=self.race_date, width=15).grid(row=0, column=3, sticky="w", padx=5, pady=2)
        
        # Dystans
        ttk.Label(race_frame, text="Dystans:").grid(row=0, column=4, sticky="e", padx=5, pady=2)
        ttk.Entry(race_frame, textvariable=self.race_distance, width=15).grid(row=0, column=5, sticky="w", padx=5, pady=2)
        
        # Miejscowość
        ttk.Label(race_frame, text="Miejscowość:").grid(row=0, column=6, sticky="e", padx=5, pady=2)
        ttk.Entry(race_frame, textvariable=self.race_location, width=20).grid(row=0, column=7, sticky="w", padx=5, pady=2)
        
        # Ramka dla wyboru logo
        logo_frame = ttk.LabelFrame(main_frame, text="Logo do PDF", padding="10")
        logo_frame.pack(fill="x", padx=10, pady=5)
        
        # Logo 1 (główne - prawe)
        ttk.Label(logo_frame, text="Logo 1 (prawe):").grid(row=0, column=0, sticky="e", padx=5, pady=2)
        self.logo1_label = ttk.Label(logo_frame, text="Nie wybrano", foreground="gray")
        self.logo1_label.grid(row=0, column=1, sticky="w", padx=5, pady=2)
        ttk.Button(logo_frame, text="Wybierz", command=self.select_logo1).grid(row=0, column=2, padx=5, pady=2)
        ttk.Button(logo_frame, text="Usuń", command=self.clear_logo1).grid(row=0, column=3, padx=5, pady=2)
        
        # Logo 2 (dodatkowe - lewe) - w tym samym wierszu
        ttk.Label(logo_frame, text="Logo 2 (lewe):").grid(row=0, column=4, sticky="e", padx=(20,5), pady=2)
        self.logo2_label = ttk.Label(logo_frame, text="Nie wybrano", foreground="gray")
        self.logo2_label.grid(row=0, column=5, sticky="w", padx=5, pady=2)
        ttk.Button(logo_frame, text="Wybierz", command=self.select_logo2).grid(row=0, column=6, padx=5, pady=2)
        ttk.Button(logo_frame, text="Usuń", command=self.clear_logo2).grid(row=0, column=7, padx=5, pady=2)
        
        # Ramka dla konfiguracji kolumn
        column_config_frame = ttk.LabelFrame(main_frame, text="Konfiguracja kolumn", padding="10")
        column_config_frame.pack(fill="x", padx=10, pady=5)
        
        # Przyciski zarządzania kolumnami
        columns_button_frame = ttk.Frame(column_config_frame)
        columns_button_frame.pack(fill="x", padx=5, pady=5)
        
        ttk.Button(columns_button_frame, text="Dodaj kolumnę", command=self.add_column).pack(side=tk.LEFT, padx=5)
        ttk.Button(columns_button_frame, text="Usuń kolumnę", command=self.remove_column).pack(side=tk.LEFT, padx=5)
        ttk.Button(columns_button_frame, text="Przesuń w lewo", command=self.move_column_left).pack(side=tk.LEFT, padx=5)
        ttk.Button(columns_button_frame, text="Przesuń w prawo", command=self.move_column_right).pack(side=tk.LEFT, padx=5)
        
        # Ramka dla mapowania kolumn
        self.mapping_frame = ttk.Frame(column_config_frame)
        self.mapping_frame.pack(fill="x", padx=5, pady=5)
        
        # Ramka dla podglądu danych
        preview_frame = ttk.LabelFrame(main_frame, text="Podgląd danych", padding="10")
        preview_frame.pack(fill="both", expand=True, padx=10, pady=5)
        
        # Wyszukiwarka - dodana na górze podglądu
        self.search_var = tk.StringVar()
        search_frame = ttk.Frame(preview_frame)
        search_frame.pack(fill="x", padx=5, pady=5)

        ttk.Label(search_frame, text="Wyszukaj:").pack(side=tk.LEFT, padx=5)
        search_entry = ttk.Entry(search_frame, textvariable=self.search_var, width=30)
        search_entry.pack(side=tk.LEFT, padx=5)
        search_entry.bind("<KeyRelease>", self.on_search)
        ttk.Button(search_frame, text="Wyczyść", command=self.clear_search).pack(side=tk.LEFT, padx=5)
        
        # Wewnętrzna ramka dla treeview i przycisków edycji
        preview_inner_frame = ttk.Frame(preview_frame)
        preview_inner_frame.pack(fill="both", expand=True)
        
        # Ramka dla indykatora ładowania i przycisków
        loading_button_frame = ttk.Frame(preview_inner_frame)
        loading_button_frame.pack(fill="x", padx=5, pady=5)

        # Przycisk edycji danych
        ttk.Button(loading_button_frame, text="Edytuj zaznaczoną komórkę", command=self.edit_cell).pack(side=tk.LEFT, padx=5)

        # POPRAWKA: Indykator ładowania w osobnej linii
        loading_frame = ttk.Frame(loading_button_frame)
        loading_frame.pack(side=tk.LEFT, fill="x", expand=True)

        self.loading_label = ttk.Label(loading_frame, textvariable=self.loading_label_var, foreground='blue')
        self.loading_label.pack(side=tk.LEFT, padx=10)

        # Przycisk PDF w prawym rogu
        generate_pdf_button = tk.Button(
            loading_button_frame, 
            text="GENERUJ PDF", 
            command=self.generate_pdf,
            bg='#FF6600',
            fg='white',
            font=('Helvetica', 12, 'bold'),
            padx=15,
            pady=5
        )
        generate_pdf_button.pack(side=tk.RIGHT, padx=15, pady=5)
        
        # Treeview dla podglądu danych
        self.tree_frame = ttk.Frame(preview_inner_frame)
        self.tree_frame.pack(fill="both", expand=True)
        
        # Utworzenie treeview i scrollbara zostanie zrobione dynamicznie w update_treeview
        self.update_treeview()
        
        # Pasek statusu
        self.status_var = tk.StringVar()
        self.status_var.set("Gotowy")
        status_bar = ttk.Label(self.root, textvariable=self.status_var, relief=tk.SUNKEN, anchor=tk.W)
        status_bar.pack(side=tk.BOTTOM, fill=tk.X)
        
        # Przyciski akcji
        action_frame = ttk.Frame(main_frame)
        action_frame.pack(fill="x", padx=10, pady=10)
        
        # Pokaż liczbę zawodników
        self.athlete_count_var = tk.StringVar()
        self.athlete_count_var.set("Zawodnicy: 0")
        ttk.Label(action_frame, textvariable=self.athlete_count_var).pack(side=tk.LEFT, padx=5)
        
        # Inicjalizuj mapowanie kolumn
        self.update_column_mapping()
        
        
    def on_search(self, event=None):
        search_text = self.search_var.get().lower()
        
        if not search_text:
            # Jeśli pole wyszukiwania jest puste, wyczyść filtrowanie
            self.filtered_athletes = []
            self.refresh_preview()
            return
        
        # Filtruj zawodników według tekstu wyszukiwania
        self.filtered_athletes = []
        
        for athlete in self.athletes:
            # Sprawdź wszystkie pola tekstowe zawodnika
            for field_name, field_value in athlete.items():
                # Jeśli pole ma wartość tekstową, sprawdź czy zawiera tekst wyszukiwania
                if isinstance(field_value, str) and search_text in field_value.lower():
                    self.filtered_athletes.append(athlete)
                    break
        
        # Odśwież podgląd z przefiltrowanymi danymi
        self.refresh_preview()

    def clear_search(self):
        """Czyści pole wyszukiwania i wyświetla wszystkie dane"""
        self.search_var.set("")
        self.filtered_athletes = []
        self.refresh_preview()
    
    def update_column_mapping(self):
        """Aktualizuje mapowanie kolumn w interfejsie"""
        # Wyczyść poprzednie widgety
        for widget in self.mapping_frame.winfo_children():
            widget.destroy()
        
        # Stwórz mapowanie dla każdej aktywnej kolumny
        self.column_vars = []
        for i, col in enumerate(self.active_columns):
            # Zmienna do przechowywania wybranego pola API
            column_var = tk.StringVar(value=col["api_options"][0])
            self.column_vars.append(column_var)
            
            # Etykieta z nazwą kolumny
            ttk.Label(self.mapping_frame, text=col["name"], anchor="center", width=12).grid(
                row=0, column=i, padx=2, pady=5)
            
            # Przygotuj listę opcji dla kolumny
            api_options = list(col["api_options"])
            
            # Jeśli to kolumna "club", dodaj wszystkie pola custom_element rozpoczynające się od custom_element260
            if col["id"] == "club":
                # Dodaj wszystkie dostępne pola custom_element
                if hasattr(self.api_client, 'custom_fields'):
                    for field in self.api_client.custom_fields:
                        if field.startswith('custom_element'):  # Pola ze wzorcem custom_element260xxx są potencjalnymi klubami
                            if field not in api_options:
                                api_options.append(field)
                                print(f"Dodano pole {field} do opcji dla kolumny {col['name']}")
                            
            # Dropdown z opcjami API
            dropdown = ttk.Combobox(self.mapping_frame, textvariable=column_var, 
                                  values=api_options, width=12, state="readonly")
            dropdown.grid(row=1, column=i, padx=2, pady=2)
            dropdown.bind("<<ComboboxSelected>>", self.refresh_preview)
    
    def update_treeview(self):
        """Aktualizuje treeview z aktualnymi kolumnami"""
        # Wyczyść poprzednie widgety
        for widget in self.tree_frame.winfo_children():
            widget.destroy()
        
        # Stwórz nowe treeview z aktualnymi kolumnami
        columns = [f"col{i}" for i in range(len(self.active_columns))]
        self.tree = ttk.Treeview(self.tree_frame, columns=columns, show="headings", height=15)
        
        # Nagłówki kolumn
        for i, col in enumerate(self.active_columns):
            self.tree.heading(columns[i], text=col["name"])
            self.tree.column(columns[i], width=80, anchor='center')
        
        # Pasek przewijania
        scrollbar = ttk.Scrollbar(self.tree_frame, orient="vertical", command=self.tree.yview)
        self.tree.configure(yscrollcommand=scrollbar.set)
        
        # Pakowanie elementów
        self.tree.pack(side="left", fill="both", expand=True)
        scrollbar.pack(side="right", fill="y")
        
        # Dodaj obsługę podwójnego kliknięcia do edycji komórki
        self.tree.bind("<Double-1>", self.on_double_click)
        
        # Odśwież dane w podglądzie
        self.refresh_preview()
    
    def refresh_preview(self, event=None):
        """Odświeża podgląd danych na podstawie aktualnych ustawień kolumn"""
        # Wyczyść istniejące dane
        for item in self.tree.get_children():
            self.tree.delete(item)
        
        # Sprawdź, czy są dane do wyświetlenia
        if not self.athletes:
            return
        
        # Jeśli filtrujemy, użyj przefiltrowanych danych, w przeciwnym razie użyj wszystkich
        display_athletes = self.filtered_athletes if self.filtered_athletes else self.athletes
        
        # Wstaw dane zawodników
        for i, athlete in enumerate(display_athletes):
            row_values = []
            
            for j, col_var in enumerate(self.column_vars):
                # Pobierz aktualnie wybrane pole API dla tej kolumny
                api_field = col_var.get()
                
                # Obsłuż specjalne przypadki
                if api_field == "full_name" or api_field == "athlete_last_name,athlete_first_name":
                    # Połącz nazwisko i imię
                    last_name = athlete.get('athlete_last_name', '')
                    first_name = athlete.get('athlete_first_name', '')
                    if last_name and first_name:
                        value = f"{last_name} {first_name}"
                    else:
                        value = last_name or first_name or ""
                elif "," in api_field:
                    # Obsługa wielokrotnych pól oddzielonych przecinkiem
                    fields = api_field.split(",")
                    values = []
                    for field in fields:
                        field_value = athlete.get(field, "")
                        if field_value:
                            values.append(str(field_value))
                    value = " ".join(values)
                else:
                    # Standardowe pole - sprawdź czy istnieje w danych zawodnika
                    value = athlete.get(api_field, "")
                
                row_values.append(value)
            
            # Wstaw wiersz do treeview
            self.tree.insert("", "end", iid=f"I{i}", values=row_values)
        
        # Aktualizuj licznik zawodników
        self.athlete_count_var.set(f"Zawodnicy: {len(display_athletes)}")
    
    def on_double_click(self, event):
        """Obsługuje podwójne kliknięcie w komórkę treeview"""
        region = self.tree.identify("region", event.x, event.y)
        if region == "cell":
            column = self.tree.identify_column(event.x)
            item = self.tree.identify_row(event.y)
            
            # Przekształć numer kolumny na indeks (usuń prefix '#')
            col_idx = int(column.replace('#', '')) - 1
            
            # Pobierz obecną wartość
            current_values = self.tree.item(item, 'values')
            
            # Edytuj komórkę
            self.edit_cell_at(item, col_idx, current_values)
    
    def add_column(self):
        """Dodaje nową kolumnę"""
        # Znajdź kolumny, które nie są aktywne
        inactive_columns = [col for col in self.all_columns if col not in self.active_columns]

        # Stwórz okno dodawania kolumny
        dialog = tk.Toplevel(self.root)
        dialog.title("Dodaj kolumnę")
        dialog.geometry("400x500")
        dialog.transient(self.root)
        dialog.grab_set()

        # Etykieta
        ttk.Label(dialog, text="Wybierz kolumnę lub utwórz własną:", font=('Helvetica', 10, 'bold')).pack(pady=10)

        # Ramka dla wyboru istniejącej kolumny
        existing_frame = ttk.LabelFrame(dialog, text="Wybierz istniejącą kolumnę")
        existing_frame.pack(fill="both", expand=True, padx=10, pady=5)

        # Lista dostępnych kolumn
        column_listbox = tk.Listbox(existing_frame, width=40, height=8)
        column_listbox.pack(fill="both", expand=True, padx=10, pady=5)
        for col in inactive_columns:
            column_listbox.insert(tk.END, f"{col['name']} - {col['description']}")

        # Ramka dla tworzenia nowej kolumny
        custom_frame = ttk.LabelFrame(dialog, text="Utwórz własną kolumnę")
        custom_frame.pack(fill="both", expand=True, padx=10, pady=5)

        ttk.Label(custom_frame, text="Nazwa kolumny:").pack(anchor='w', padx=10, pady=2)
        column_name_var = tk.StringVar()
        ttk.Entry(custom_frame, textvariable=column_name_var, width=30).pack(fill='x', padx=10, pady=2)

        ttk.Label(custom_frame, text="Opis kolumny:").pack(anchor='w', padx=10, pady=2)
        column_desc_var = tk.StringVar()
        ttk.Entry(custom_frame, textvariable=column_desc_var, width=30).pack(fill='x', padx=10, pady=2)

        ttk.Label(custom_frame, text="Wybierz atrybuty API:").pack(anchor='w', padx=10, pady=2)
        api_attr_listbox = tk.Listbox(custom_frame, width=40, height=8, selectmode=tk.MULTIPLE)
        api_attr_listbox.pack(fill="both", expand=True, padx=10, pady=5)
        for attr in sorted(self.all_api_attributes):
            api_attr_listbox.insert(tk.END, attr)

        # Pasek przewijania dla listy atrybutów
        scrollbar = ttk.Scrollbar(api_attr_listbox, orient="vertical", command=api_attr_listbox.yview)
        api_attr_listbox.configure(yscrollcommand=scrollbar.set)
        scrollbar.pack(side="right", fill="y")

        # Ramka dla przycisków
        button_frame = ttk.Frame(dialog)
        button_frame.pack(fill="x", padx=10, pady=10)

        # Funkcja: dodaj istniejącą kolumnę
        def add_existing_column():
            selection = column_listbox.curselection()
            if selection:
                idx = selection[0]
                col = inactive_columns[idx]
                self.active_columns.append(col)
                self.update_column_mapping()
                self.update_treeview()
                dialog.destroy()

        # Funkcja: dodaj własną kolumnę
        def add_custom_column():
            column_name = column_name_var.get().strip()
            column_desc = column_desc_var.get().strip()
            if not column_name:
                messagebox.showerror("Błąd", "Podaj nazwę kolumny!")
                return

            attr_selection = api_attr_listbox.curselection()
            if not attr_selection:
                messagebox.showerror("Błąd", "Wybierz co najmniej jeden atrybut API!")
                return

            selected_attrs = [api_attr_listbox.get(i) for i in attr_selection]
            new_column = {
                "id": column_name.lower().replace(" ", "_"),
                "name": column_name,
                "description": column_desc if column_desc else column_name,
                "api_options": selected_attrs,
                "selected": True
            }
            self.active_columns.append(new_column)
            self.update_column_mapping()
            self.update_treeview()
            dialog.destroy()

        # Przyciski
        ttk.Button(button_frame, text="Dodaj istniejącą", command=add_existing_column).pack(side=tk.LEFT, padx=5)
        ttk.Button(button_frame, text="Dodaj własną", command=add_custom_column).pack(side=tk.LEFT, padx=5)
        ttk.Button(button_frame, text="Anuluj", command=dialog.destroy).pack(side=tk.RIGHT, padx=5)

        
    def add_existing_column():
            selection = column_listbox.curselection()
            if selection:
                idx = selection[0]
                col = inactive_columns[idx]
                
                # Dodaj kolumnę do aktywnych
                self.active_columns.append(col)
                
                # Zaktualizuj interfejs
                self.update_column_mapping()
                self.update_treeview()
                
                dialog.destroy()
        
    def add_custom_column():
            column_name = column_name_var.get().strip()
            column_desc = column_desc_var.get().strip()
            
            # Sprawdź czy podano nazwę kolumny
            if not column_name:
                messagebox.showerror("Błąd", "Podaj nazwę kolumny!")
                return
            
            # Pobierz wybrane atrybuty API
            attr_selection = api_attr_listbox.curselection()
            if not attr_selection:
                messagebox.showerror("Błąd", "Wybierz co najmniej jeden atrybut API!")
                return
            
            # Pobierz wybrane atrybuty
            selected_attrs = [api_attr_listbox.get(i) for i in attr_selection]
            
            # Stwórz nową definicję kolumny
            new_column = {
                "id": column_name.lower().replace(" ", "_"),
                "name": column_name,
                "description": column_desc if column_desc else column_name,
                "api_options": selected_attrs,
                "selected": True
            }
            
            # Dodaj kolumnę do aktywnych
            self.active_columns.append(new_column)
            
            # Zaktualizuj interfejs
            self.update_column_mapping()
            self.update_treeview()
            
            dialog.destroy()
        
            ttk.Button(button_frame, text="Dodaj istniejącą", command=add_existing_column).pack(side=tk.LEFT, padx=5)
            ttk.Button(button_frame, text="Dodaj własną", command=add_custom_column).pack(side=tk.LEFT, padx=5)
            ttk.Button(button_frame, text="Anuluj", command=dialog.destroy).pack(side=tk.RIGHT, padx=5)
    
    def remove_column(self):
        """Usuwa wybraną kolumnę"""
        if len(self.active_columns) <= 1:
            messagebox.showinfo("Informacja", "Musi pozostać przynajmniej jedna kolumna.")
            return
        
        # Stwórz okno wyboru kolumny do usunięcia
        dialog = tk.Toplevel(self.root)
        dialog.title("Usuń kolumnę")
        dialog.geometry("300x400")
        dialog.transient(self.root)
        dialog.grab_set()
        
        # Etykieta
        ttk.Label(dialog, text="Wybierz kolumnę do usunięcia:").pack(pady=10)
        
        # Lista aktywnych kolumn
        column_listbox = tk.Listbox(dialog, width=40, height=15)
        column_listbox.pack(fill="both", expand=True, padx=10, pady=5)
        
        # Wypełnij listbox
        for col in self.active_columns:
            column_listbox.insert(tk.END, f"{col['name']} - {col['description']}")
        
        # Przyciski
        button_frame = ttk.Frame(dialog)
        button_frame.pack(fill="x", padx=10, pady=10)
        
        def remove_selected_column():
            selection = column_listbox.curselection()
            if selection:
                idx = selection[0]
                
                # Usuń kolumnę z aktywnych
                self.active_columns.pop(idx)
                
                # Zaktualizuj interfejs
                self.update_column_mapping()
                self.update_treeview()
                
                dialog.destroy()
        
        ttk.Button(button_frame, text="Usuń", command=remove_selected_column).pack(side=tk.LEFT, padx=5)
        ttk.Button(button_frame, text="Anuluj", command=dialog.destroy).pack(side=tk.RIGHT, padx=5)
    
    def move_column_left(self):
        """Przesuwa wybraną kolumnę w lewo"""
        # Stwórz okno wyboru kolumny
        dialog = tk.Toplevel(self.root)
        dialog.title("Przesuń kolumnę w lewo")
        dialog.geometry("300x400")
        dialog.transient(self.root)
        dialog.grab_set()
        
        # Etykieta
        ttk.Label(dialog, text="Wybierz kolumnę do przesunięcia:").pack(pady=10)
        
        # Lista kolumn (pomijamy pierwszą, bo nie można jej przesunąć w lewo)
        column_listbox = tk.Listbox(dialog, width=40, height=15)
        column_listbox.pack(fill="both", expand=True, padx=10, pady=5)
        
        # Wypełnij listbox
        for i, col in enumerate(self.active_columns):
            if i > 0:  # Pomijamy pierwszą kolumnę
                column_listbox.insert(tk.END, f"{col['name']} - {col['description']}")
        
        # Przyciski
        button_frame = ttk.Frame(dialog)
        button_frame.pack(fill="x", padx=10, pady=10)
        
        def move_selected_column():
            selection = column_listbox.curselection()
            if selection:
                idx = selection[0] + 1  # +1 bo pominęliśmy pierwszą kolumnę
                
                # Zamień miejscami z kolumną na lewo
                self.active_columns[idx], self.active_columns[idx-1] = self.active_columns[idx-1], self.active_columns[idx]
                
                # Zaktualizuj interfejs
                self.update_column_mapping()
                self.update_treeview()
                
                dialog.destroy()
        
        ttk.Button(button_frame, text="Przesuń", command=move_selected_column).pack(side=tk.LEFT, padx=5)
        ttk.Button(button_frame, text="Anuluj", command=dialog.destroy).pack(side=tk.RIGHT, padx=5)
    
    def move_column_right(self):
        """Przesuwa wybraną kolumnę w prawo"""
        # Stwórz okno wyboru kolumny
        dialog = tk.Toplevel(self.root)
        dialog.title("Przesuń kolumnę w prawo")
        dialog.geometry("300x400")
        dialog.transient(self.root)
        dialog.grab_set()
        
        # Etykieta
        ttk.Label(dialog, text="Wybierz kolumnę do przesunięcia:").pack(pady=10)
        
        # Lista kolumn (pomijamy ostatnią, bo nie można jej przesunąć w prawo)
        column_listbox = tk.Listbox(dialog, width=40, height=15)
        column_listbox.pack(fill="both", expand=True, padx=10, pady=5)
        
        # Wypełnij listbox
        for i, col in enumerate(self.active_columns):
            if i < len(self.active_columns) - 1:  # Pomijamy ostatnią kolumnę
                column_listbox.insert(tk.END, f"{col['name']} - {col['description']}")
        
        # Przyciski
        button_frame = ttk.Frame(dialog)
        button_frame.pack(fill="x", padx=10, pady=10)
        
        def move_selected_column():
            selection = column_listbox.curselection()
            if selection:
                idx = selection[0]
                
                # Zamień miejscami z kolumną na prawo
                self.active_columns[idx], self.active_columns[idx+1] = self.active_columns[idx+1], self.active_columns[idx]
                
                # Zaktualizuj interfejs
                self.update_column_mapping()
                self.update_treeview()
                
                dialog.destroy()
        
        ttk.Button(button_frame, text="Przesuń", command=move_selected_column).pack(side=tk.LEFT, padx=5)
        ttk.Button(button_frame, text="Anuluj", command=dialog.destroy).pack(side=tk.RIGHT, padx=5)
    
    def edit_cell(self):
        """Edytuje zaznaczoną komórkę"""
        selection = self.tree.selection()
        if not selection:
            messagebox.showinfo("Informacja", "Zaznacz wiersz do edycji.")
            return
        
        # Stwórz okno wyboru kolumny
        dialog = tk.Toplevel(self.root)
        dialog.title("Wybierz kolumnę do edycji")
        dialog.geometry("300x400")
        dialog.transient(self.root)
        dialog.grab_set()
        
        # Etykieta
        ttk.Label(dialog, text="Wybierz kolumnę:").pack(pady=10)
        
        # Lista kolumn
        column_listbox = tk.Listbox(dialog, width=40, height=15)
        column_listbox.pack(fill="both", expand=True, padx=10, pady=5)
        
    def add_existing_column():
            selection = column_listbox.curselection()
            if selection:
                idx = selection[0]
                col = inactive_columns[idx]
                
                # Dodaj kolumnę do aktywnych
                self.active_columns.append(col)
                
                # Zaktualizuj interfejs
                self.update_column_mapping()
                self.update_treeview()
                
                dialog.destroy()
        
    def add_custom_column():
            column_name = column_name_var.get().strip()
            column_desc = column_desc_var.get().strip()
            
            # Sprawdź czy podano nazwę kolumny
            if not column_name:
                messagebox.showerror("Błąd", "Podaj nazwę kolumny!")
                return
            
            # Pobierz wybrane atrybuty API
            attr_selection = api_attr_listbox.curselection()
            if not attr_selection:
                messagebox.showerror("Błąd", "Wybierz co najmniej jeden atrybut API!")
                return
            
            # Pobierz wybrane atrybuty
            selected_attrs = [api_attr_listbox.get(i) for i in attr_selection]
            
            # Stwórz nową definicję kolumny
            new_column = {
                "id": column_name.lower().replace(" ", "_"),
                "name": column_name,
                "description": column_desc if column_desc else column_name,
                "api_options": selected_attrs,
                "selected": True
            }
            
            # Dodaj kolumnę do aktywnych
            self.active_columns.append(new_column)
            
            # Zaktualizuj interfejs
            self.update_column_mapping()
            self.update_treeview()
            
            dialog.destroy()
        
            ttk.Button(button_frame, text="Dodaj istniejącą", command=add_existing_column).pack(side=tk.LEFT, padx=5)
            ttk.Button(button_frame, text="Dodaj własną", command=add_custom_column).pack(side=tk.LEFT, padx=5)
            ttk.Button(button_frame, text="Anuluj", command=dialog.destroy).pack(side=tk.RIGHT, padx=5)
    
    def remove_column(self):
        """Usuwa wybraną kolumnę"""
        if len(self.active_columns) <= 1:
            messagebox.showinfo("Informacja", "Musi pozostać przynajmniej jedna kolumna.")
            return
        
        # Stwórz okno wyboru kolumny do usunięcia
        dialog = tk.Toplevel(self.root)
        dialog.title("Usuń kolumnę")
        dialog.geometry("300x400")
        dialog.transient(self.root)
        dialog.grab_set()
        
        # Etykieta
        ttk.Label(dialog, text="Wybierz kolumnę do usunięcia:").pack(pady=10)
        
        # Lista aktywnych kolumn
        column_listbox = tk.Listbox(dialog, width=40, height=15)
        column_listbox.pack(fill="both", expand=True, padx=10, pady=5)
        
        # Wypełnij listbox
        for col in self.active_columns:
            column_listbox.insert(tk.END, f"{col['name']} - {col['description']}")
        
        # Przyciski
        button_frame = ttk.Frame(dialog)
        button_frame.pack(fill="x", padx=10, pady=10)
        
        def remove_selected_column():
            selection = column_listbox.curselection()
            if selection:
                idx = selection[0]
                
                # Usuń kolumnę z aktywnych
                self.active_columns.pop(idx)
                
                # Zaktualizuj interfejs
                self.update_column_mapping()
                self.update_treeview()
                
                dialog.destroy()
        
        ttk.Button(button_frame, text="Usuń", command=remove_selected_column).pack(side=tk.LEFT, padx=5)
        ttk.Button(button_frame, text="Anuluj", command=dialog.destroy).pack(side=tk.RIGHT, padx=5)
    
    def move_column_left(self):
        """Przesuwa wybraną kolumnę w lewo"""
        # Stwórz okno wyboru kolumny
        dialog = tk.Toplevel(self.root)
        dialog.title("Przesuń kolumnę w lewo")
        dialog.geometry("300x400")
        dialog.transient(self.root)
        dialog.grab_set()
        
        # Etykieta
        ttk.Label(dialog, text="Wybierz kolumnę do przesunięcia:").pack(pady=10)
        
        # Lista kolumn (pomijamy pierwszą, bo nie można jej przesunąć w lewo)
        column_listbox = tk.Listbox(dialog, width=40, height=15)
        column_listbox.pack(fill="both", expand=True, padx=10, pady=5)
        
        # Wypełnij listbox
        for i, col in enumerate(self.active_columns):
            if i > 0:  # Pomijamy pierwszą kolumnę
                column_listbox.insert(tk.END, f"{col['name']} - {col['description']}")
        
        # Przyciski
        button_frame = ttk.Frame(dialog)
        button_frame.pack(fill="x", padx=10, pady=10)
        
        def move_selected_column():
            selection = column_listbox.curselection()
            if selection:
                idx = selection[0] + 1  # +1 bo pominęliśmy pierwszą kolumnę
                
                # Zamień miejscami z kolumną na lewo
                self.active_columns[idx], self.active_columns[idx-1] = self.active_columns[idx-1], self.active_columns[idx]
                
                # Zaktualizuj interfejs
                self.update_column_mapping()
                self.update_treeview()
                
                dialog.destroy()
        
        ttk.Button(button_frame, text="Przesuń", command=move_selected_column).pack(side=tk.LEFT, padx=5)
        ttk.Button(button_frame, text="Anuluj", command=dialog.destroy).pack(side=tk.RIGHT, padx=5)
    
    def move_column_right(self):
        """Przesuwa wybraną kolumnę w prawo"""
        # Stwórz okno wyboru kolumny
        dialog = tk.Toplevel(self.root)
        dialog.title("Przesuń kolumnę w prawo")
        dialog.geometry("300x400")
        dialog.transient(self.root)
        dialog.grab_set()
        
        # Etykieta
        ttk.Label(dialog, text="Wybierz kolumnę do przesunięcia:").pack(pady=10)
        
        # Lista kolumn (pomijamy ostatnią, bo nie można jej przesunąć w prawo)
        column_listbox = tk.Listbox(dialog, width=40, height=15)
        column_listbox.pack(fill="both", expand=True, padx=10, pady=5)
        
        # Wypełnij listbox
        for i, col in enumerate(self.active_columns):
            if i < len(self.active_columns) - 1:  # Pomijamy ostatnią kolumnę
                column_listbox.insert(tk.END, f"{col['name']} - {col['description']}")
        
        # Przyciski
        button_frame = ttk.Frame(dialog)
        button_frame.pack(fill="x", padx=10, pady=10)
        
        def move_selected_column():
            selection = column_listbox.curselection()
            if selection:
                idx = selection[0]
                
                # Zamień miejscami z kolumną na prawo
                self.active_columns[idx], self.active_columns[idx+1] = self.active_columns[idx+1], self.active_columns[idx]
                
                # Zaktualizuj interfejs
                self.update_column_mapping()
                self.update_treeview()
                
                dialog.destroy()
        
        ttk.Button(button_frame, text="Przesuń", command=move_selected_column).pack(side=tk.LEFT, padx=5)
        ttk.Button(button_frame, text="Anuluj", command=dialog.destroy).pack(side=tk.RIGHT, padx=5)
    
    def edit_cell(self):
        """Edytuje zaznaczoną komórkę"""
        selection = self.tree.selection()
        if not selection:
            messagebox.showinfo("Informacja", "Zaznacz wiersz do edycji.")
            return
        
        # Stwórz okno wyboru kolumny
        dialog = tk.Toplevel(self.root)
        dialog.title("Wybierz kolumnę do edycji")
        dialog.geometry("300x400")
        dialog.transient(self.root)
        dialog.grab_set()
        
        # Etykieta
        ttk.Label(dialog, text="Wybierz kolumnę:").pack(pady=10)
        
        # Lista kolumn
        column_listbox = tk.Listbox(dialog, width=40, height=15)
        column_listbox.pack(fill="both", expand=True, padx=10, pady=5)
        
        # Wypełnij listbox
        for col in self.active_columns:
            column_listbox.insert(tk.END, col['name'])
        
        # Przyciski
        button_frame = ttk.Frame(dialog)
        button_frame.pack(fill="x", padx=10, pady=10)
        
        def select_column_for_edit():
            column_selection = column_listbox.curselection()
            if column_selection:
                col_idx = column_selection[0]
                item = selection[0]
                
                # Pobierz obecną wartość
                current_values = self.tree.item(item, 'values')
                
                # Edytuj komórkę
                self.edit_cell_at(item, col_idx, current_values)
                
                dialog.destroy()
        
        ttk.Button(button_frame, text="Edytuj", command=select_column_for_edit).pack(side=tk.LEFT, padx=5)
        ttk.Button(button_frame, text="Anuluj", command=dialog.destroy).pack(side=tk.RIGHT, padx=5)
    
    def edit_cell_at(self, item, col_idx, current_values):
        # Pobierz nazwę kolumny i obecną wartość
        column_name = self.active_columns[col_idx]['name']
        current_value = current_values[col_idx] if len(current_values) > col_idx else ""
    
        # Pytaj o nową wartość
        new_value = simpledialog.askstring(
            f"Edytuj {column_name}", 
            f"Wprowadź nową wartość dla {column_name}:",
            initialvalue=current_value
        )
    
        if new_value is not None:  # None oznacza anulowanie
            # Aktualizuj wartość w treeview
            new_values = list(current_values)
            if len(new_values) <= col_idx:
                new_values.extend([""] * (col_idx + 1 - len(new_values)))
            new_values[col_idx] = new_value
            self.tree.item(item, values=new_values)
            
            # Aktualizuj dane zawodnika w pamięci
            try:
                # Znajdź zawodnika po numerze zawodnika i/lub innych identyfikatorach
                athlete_idx = int(item.replace('I', ''))
                display_athletes = self.filtered_athletes if self.filtered_athletes else self.athletes
                
                if 0 <= athlete_idx < len(display_athletes):
                    # Znajdź odpowiednie pole API
                    api_field = self.column_vars[col_idx].get()
                    
                    # Obsłuż specjalne przypadki
                    if api_field == "full_name" or api_field == "athlete_last_name,athlete_first_name":
                        # Rozdziel na nazwisko i imię
                        name_parts = new_value.split()
                        if len(name_parts) >= 2:
                            display_athletes[athlete_idx]['athlete_last_name'] = name_parts[0]
                            display_athletes[athlete_idx]['athlete_first_name'] = " ".join(name_parts[1:])
                    else:
                        # Standardowa aktualizacja pola
                        display_athletes[athlete_idx][api_field] = new_value
                    
                    # Jeśli używamy filtrowanych atletów, aktualizuj również w głównej liście
                    if self.filtered_athletes:
                        # Znajdź zawodnika w oryginalnej liście po BIB lub innym identyfikatorze
                        athlete_bib = display_athletes[athlete_idx].get('entry_bib')
                        if athlete_bib:
                            for i, orig_athlete in enumerate(self.athletes):
                                if orig_athlete.get('entry_bib') == athlete_bib:
                                    if api_field == "full_name" or api_field == "athlete_last_name,athlete_first_name":
                                        # Rozdziel na nazwisko i imię
                                        name_parts = new_value.split()
                                        if len(name_parts) >= 2:
                                            orig_athlete['athlete_last_name'] = name_parts[0]
                                            orig_athlete['athlete_first_name'] = " ".join(name_parts[1:])
                                    else:
                                        # Standardowa aktualizacja pola
                                        orig_athlete[api_field] = new_value
            except Exception as e:
                print(f"Błąd podczas aktualizacji danych zawodnika: {e}")
    
    def update_loading_label(self, text):
        """Aktualizuje etykietę informującą o ładowaniu danych"""
        self.loading_label_var.set(text)
        self.root.update_idletasks()
    
    def update_reg_choice_dropdown(self):
        """Aktualizuje dropdown z dostępnymi dystansami"""
        # Przygotuj listę wyświetlanych wartości
        reg_choice_values = []
        
        # Dodaj dystanse z ich nazwami (bez ID w tekście)
        if self.reg_choices:
            print(f"Dostępne dystanse: {len(self.reg_choices)}")
            for choice in self.reg_choices:
                display_text = choice['name']  # Używamy tylko nazwy dystansu
                print(f"Dodaję dystans: {display_text} (ID: {choice['id']})")
                reg_choice_values.append(display_text)
        
        # Dodatkowo, przygotuj listę unikalnych dystansów na podstawie race_name/race_distance
        if self.api_client.cache['openResults']:
            unique_race_names = set()
            for athlete in self.api_client.cache['openResults']:
                # Najpierw sprawdź reg_choice_name jako źródło nazwy dystansu
                race_name = athlete.get('reg_choice_name', '')
                if not race_name:
                    # Następnie sprawdź race_name i race_distance
                    race_name = athlete.get('race_name', athlete.get('race_distance', ''))
                
                if race_name and race_name not in unique_race_names and race_name not in reg_choice_values:
                    unique_race_names.add(race_name)
            
            if unique_race_names:
                # Dodaj separator tylko jeśli mamy już inne dystanse i nowe nazwy
                if reg_choice_values and unique_race_names:
                    reg_choice_values.append("----- Biegi według nazwy -----")
                
                for name in sorted(unique_race_names):
                    reg_choice_values.append(name)
        
        # Dodaj "Wszystkie dystanse" na początku
        if reg_choice_values:
            reg_choice_values.insert(0, "Wszystkie dystanse")
        else:
            reg_choice_values = ["Wszystkie dystanse"]
        
        # Aktualizuj dropdown
        self.reg_choice_dropdown['values'] = reg_choice_values
        
        # Ustaw domyślnie "Wszystkie dystanse"
        self.selected_reg_choice.set(reg_choice_values[0])
    
    def on_reg_choice_selected(self, event=None):
        """Obsługuje wybór dystansu z dropdown"""
        selected = self.selected_reg_choice.get()
        
        # Wyświetl komunikat o pobieraniu danych
        self.update_loading_label(f"Filtrowanie danych dla dystansu {selected}...")
        self.status_var.set(f"Filtrowanie wyników dla dystansu: {selected}")
        self.root.update_idletasks()
        
        if selected == "Wszystkie dystanse":
            # Wybrano "Wszystkie dystanse"
            self.current_reg_choice_id = None
            self.current_race_name = None
            self.race_distance.set("")  # Wyczyść pole dystansu
            
            # Po prostu pokaż wszystkie wyniki, bez filtrowania
            self.athletes = self.api_client.cache['openResults']
            self.refresh_preview()
            
            # Aktualizuj licznik zawodników
            self.athlete_count_var.set(f"Zawodnicy: {len(self.athletes)}")
            
            # Zaktualizuj status
            self.update_loading_label("")
            self.status_var.set(f"Pokazano wszystkie wyniki: {len(self.athletes)} zawodników")
            
        elif selected == "----- Biegi według nazwy -----":
            # To jest separator, nie rób nic
            pass
            
        else:
            # Sprawdź, czy to dystans z API (reg_choice) czy nazwa biegu
            reg_choice_id = None
            race_name = None
            
            # Najpierw sprawdź, czy to dystans z reg_choices
            for choice in self.reg_choices:
                if choice['name'] == selected:
                    reg_choice_id = choice['id']
                    race_name = choice['name']
                    # Ustaw wartość dystansu w polu
                    self.race_distance.set(choice['name'])
                    print(f"Wybrano dystans: {choice['name']} (ID: {reg_choice_id})")
                    
                    # Zapisz ID wybranego dystansu
                    self.current_reg_choice_id = reg_choice_id
                    self.current_race_name = race_name
                    break
            
            # Jeśli nie znaleziono dystansu w reg_choices, to prawdopodobnie nazwa biegu
            if not reg_choice_id:
                race_name = selected
                self.race_distance.set(race_name)
                print(f"Wybrano bieg według nazwy: {race_name}")
                
                # Zapisz nazwę wybranego biegu
                self.current_reg_choice_id = None
                self.current_race_name = race_name
            
            # Filtruj dane bezpośrednio z cache według kryteriów
            all_athletes = self.api_client.cache['openResults']
            filtered_athletes = []
            
            print(f"Filtrowanie zawodników: wszystkich {len(all_athletes)}")
            
            for athlete in all_athletes:
                # Najpierw sprawdź po reg_choice_id, jeśli dostępne
                if reg_choice_id and athlete.get('reg_choice_id') == reg_choice_id:
                    filtered_athletes.append(athlete)
                    continue
                    
                # Następnie sprawdź po reg_choice_name, jeśli dostępne
                if race_name and athlete.get('reg_choice_name') == race_name:
                    filtered_athletes.append(athlete)
                    continue
                
                # Dopiero na końcu sprawdź po race_name/race_distance
                if race_name:
                    athlete_race = athlete.get('race_name', '')
                    athlete_distance = athlete.get('race_distance', '')
                    
                    # Jeśli którekolwiek pole pasuje do wybranego dystansu, dodaj zawodnika
                    if athlete_race == race_name or athlete_distance == race_name:
                        filtered_athletes.append(athlete)
            
            # Aktualizuj dane zawodników
            self.athletes = filtered_athletes
            
            # Jeśli nie znaleziono żadnych wyników, pokaż ostrzeżenie
            if not filtered_athletes:
                messagebox.showwarning(
                    "Brak wyników", 
                    f"Nie znaleziono zawodników dla biegu '{race_name}'. Sprawdź nazwę biegu."
                )
            
            # Odśwież widok
            self.refresh_preview()
            
            # Aktualizuj licznik zawodników
            self.athlete_count_var.set(f"Zawodnicy: {len(self.athletes)}")
            
            # Zaktualizuj status
            self.update_loading_label("")
            self.status_var.set(f"Odfiltrowano {len(self.athletes)} zawodników dla biegu {race_name}")
            
    def fetch_data(self):
        """Pobiera dane z API na podstawie ID wydarzenia"""
        # Sprawdź, czy podano ID wydarzenia
        event_id = self.event_id.get().strip()
        if not event_id:
            messagebox.showerror("Błąd", "Podaj ID wydarzenia!")
            return
        
        # Aktualizuj status
        self.update_loading_label("Pobieranie danych wydarzenia...")
        self.status_var.set("Pobieranie danych...")
        self.root.update_idletasks()
        
        # Ustaw ID wydarzenia w kliencie API
        self.api_client.config['eventId'] = event_id
        
        try:
            # Pobierz informacje o wydarzeniu
            event_info = self.api_client.fetch_event_info()
            
            if not event_info:
                messagebox.showerror("Błąd", "Nie udało się pobrać danych wydarzenia!")
                self.update_loading_label("")
                self.status_var.set("Błąd pobierania danych")
                return
            
            # Ustaw dane wydarzenia w GUI
            self.race_name.set(event_info.get('event_name', ''))
            self.race_date.set(event_info.get('formatted_date', ''))
            self.race_location.set(event_info.get('location', '').split(',')[0].strip())
            
            # Pobierz dostępne dystanse
            self.reg_choices = self.api_client.cache['regChoices']
            
            # Aktualizuj status
            self.update_loading_label("Pobieranie wyników...")
            
            # Pobierz wszystkie dane
            data_result = self.api_client.fetch_all_data()
            
            if not data_result:
                messagebox.showerror("Błąd", "Nie udało się pobrać wyników!")
                self.update_loading_label("")
                self.status_var.set("Błąd pobierania wyników")
                return
            
            # Załaduj dane do listy zawodników
            self.athletes = list(self.api_client.cache['openResults'])
            
            # Aktualizuj dropdown z dystansami - ważne po pobraniu wszystkich danych
            self.update_reg_choice_dropdown()
            
            # Sortuj według miejsca ogólnego
            self.athletes.sort(key=lambda x: int(x['overall_place']) if x.get('overall_place', '').isdigit() else 9999)
            
            # Aktualizuj treeview
            self.refresh_preview()
            
            # Aktualizuj status
            total_athletes = len(self.athletes)
            self.update_loading_label("")
            self.status_var.set(f"Pobrano dane dla {total_athletes} zawodników")
            
            # Pokaż podsumowanie
            messagebox.showinfo("Pobrano dane", 
                               f"Nazwa biegu: {self.race_name.get()}\n"
                               f"Data: {self.race_date.get()}\n"
                               f"Liczba zawodników: {total_athletes}\n"
                               f"Liczba dystansów: {len(self.reg_choices)}")
        except Exception as e:
            self.update_loading_label("")
            self.status_var.set("Błąd pobierania danych")
            messagebox.showerror("Błąd", f"Wystąpił błąd podczas pobierania danych: {str(e)}")
            import traceback
            traceback.print_exc()
    
    def refresh_data(self, reg_choice_id=None, race_name=None):
        """Odświeża dane, opcjonalnie filtrując po dystansie"""
        # Sprawdź, czy mamy ustawione ID wydarzenia
        if not self.api_client.config['eventId']:
            messagebox.showerror("Błąd", "Najpierw podaj ID wydarzenia i pobierz dane!")
            return
        
        # Wyświetl komunikat o pobieraniu danych
        self.update_loading_label("Odświeżanie danych...")
        self.status_var.set("Odświeżanie danych...")
        self.root.update_idletasks()
        
        try:
            # Uruchom pobieranie w osobnym wątku, aby nie blokować GUI
            def update_data():
                try:
                    # Jeśli mamy określony dystans (reg_choice_id), pobierz dane tylko dla tego dystansu
                    if reg_choice_id:
                        # Pobierz wyniki dla konkretnego dystansu
                        open_results = self.api_client.fetch_open_results(True, reg_choice_id)
                        self.api_client.fetch_sex_results(True, reg_choice_id)
                        self.api_client.fetch_age_results(True, reg_choice_id)
                        
                        # Załaduj dane do listy zawodników
                        self.athletes = list(self.api_client.cache['openResults'])
                    else:
                        # Jeśli nie mamy ID dystansu, sprawdź czy mamy nazwę biegu do filtrowania
                        if race_name:
                            # Pobierz wszystkie wyniki bez filtrowania po ID dystansu
                            open_results = self.api_client.fetch_open_results(True)
                            self.api_client.fetch_sex_results(True)
                            self.api_client.fetch_age_results(True)
                            
                            # Filtruj po nazwie biegu
                            self.athletes = [athlete for athlete in self.api_client.cache['openResults'] 
                                           if athlete.get('reg_choice_name', '') == race_name or
                                              athlete.get('race_name', '') == race_name or
                                              athlete.get('race_distance', '') == race_name]
                        else:
                            # Pobierz wszystkie dane bez filtrowania
                            data_result = self.api_client.fetch_all_data()
                            
                            # Załaduj dane do listy zawodników
                            self.athletes = list(self.api_client.cache['openResults'])
                    
                    # Sortuj według miejsca ogólnego
                    self.athletes.sort(key=lambda x: int(x['overall_place']) if x.get('overall_place', '').isdigit() else 9999)
                    
                    # Aktualizuj GUI w wątku głównym
                    self.root.after(0, self.update_after_refresh, len(self.athletes))
                except Exception as e:
                    # Aktualizuj GUI w wątku głównym
                    self.root.after(0, self.show_error, str(e))
            
            # Uruchom wątek
            thread = threading.Thread(target=update_data)
            thread.daemon = True
            thread.start()
        except Exception as e:
            self.update_loading_label("")
            self.status_var.set("Błąd odświeżania danych")
            messagebox.showerror("Błąd", f"Wystąpił błąd podczas odświeżania danych: {str(e)}")
    
    def update_after_refresh(self, total_athletes):
        """Aktualizuje interfejs po odświeżeniu danych"""
        # Aktualizuj dropdown z dystansami
        self.update_reg_choice_dropdown()
        
        # Aktualizuj treeview
        self.refresh_preview()
        
        # Aktualizuj status
        self.update_loading_label("")
        self.status_var.set(f"Odświeżono dane dla {total_athletes} zawodników")
    
    def show_error(self, error_message):
        """Wyświetla błąd w interfejsie"""
        self.update_loading_label("")
        self.status_var.set("Błąd odświeżania danych")
        messagebox.showerror("Błąd", f"Wystąpił błąd podczas odświeżania danych: {error_message}")
    
    def select_logo1(self):
        """Wybiera plik logo 1"""
        filename = filedialog.askopenfilename(
            title="Wybierz logo 1 (prawe)",
            filetypes=[
                ("Pliki obrazów", "*.png *.jpg *.jpeg *.gif *.bmp"),
                ("PNG", "*.png"),
                ("JPEG", "*.jpg *.jpeg"),
                ("Wszystkie pliki", "*.*")
            ]
        )
        if filename:
            self.api_client.logo1_path = filename
            self.logo1_label.config(text=os.path.basename(filename), foreground="black")
            self.status_var.set(f"Wybrano logo 1: {os.path.basename(filename)}")
    
    def select_logo2(self):
        """Wybiera plik logo 2"""
        filename = filedialog.askopenfilename(
            title="Wybierz logo 2 (lewe)",
            filetypes=[
                ("Pliki obrazów", "*.png *.jpg *.jpeg *.gif *.bmp"),
                ("PNG", "*.png"),
                ("JPEG", "*.jpg *.jpeg"),
                ("Wszystkie pliki", "*.*")
            ]
        )
        if filename:
            self.api_client.logo2_path = filename
            self.logo2_label.config(text=os.path.basename(filename), foreground="black")
            self.status_var.set(f"Wybrano logo 2: {os.path.basename(filename)}")
    
    def clear_logo1(self):
        """Usuwa wybrane logo 1"""
        self.api_client.logo1_path = ""
        self.logo1_label.config(text="Nie wybrano", foreground="gray")
        self.status_var.set("Usunięto logo 1")
    
    def clear_logo2(self):
        """Usuwa wybrane logo 2"""
        self.api_client.logo2_path = ""
        self.logo2_label.config(text="Nie wybrano", foreground="gray")
        self.status_var.set("Usunięto logo 2")
    
    def generate_pdf(self):
        if not self.athletes:
            messagebox.showinfo("Informacja", "Brak danych do wygenerowania PDF.")
            return
        
        try:
            # Aktualizuj status
            self.update_loading_label("Generowanie pliku PDF...")
            self.status_var.set("Generowanie pliku PDF...")
            self.root.update_idletasks()
            
            # Przygotuj automatyczną nazwę pliku
            race_name = self.race_name.get().strip()
            race_distance = self.race_distance.get().strip()
            
            default_filename = f"wyniki_{race_name.replace(' ', '_')}_{race_distance.replace(' ', '_')}.pdf" if race_name and race_distance else "wyniki.pdf"
            
            # Zapytaj o plik docelowy z automatyczną nazwą
            filename = filedialog.asksaveasfilename(
                defaultextension=".pdf",
                initialfile=default_filename,
                filetypes=[("PDF files", "*.pdf")],
                title="Zapisz plik wyników jako PDF"
            )
            
            if not filename:
                self.update_loading_label("")
                self.status_var.set("Gotowy")
                return  # Anulowano wybór pliku
            
            # Przygotuj dane
            title = self.race_name.get() or "Wyniki zawodów"
            date = self.race_date.get() or ""
            location = self.race_location.get() or ""
            distance = self.race_distance.get() or ""
            
            # Zarejestruj czcionkę obsługującą polskie znaki
            try:
                pdfmetrics.registerFont(TTFont('DejaVuSans', 'DejaVuSans.ttf'))
                pdfmetrics.registerFont(TTFont('DejaVuSans-Bold', 'DejaVuSans-Bold.ttf'))
            except:
                print("Ostrzeżenie: Nie znaleziono czcionki DejaVuSans, używam domyślnej czcionki")
            
            # Ustalenie orientacji strony (zawsze landscape dla wyników biegów)
            page_width, page_height = landscape(A4)
            
            # Tworzenie dokumentu PDF z odpowiednimi marginesami
            doc = SimpleDocTemplate(
                filename,
                pagesize=(page_width, page_height),
                rightMargin=10 * mm,
                leftMargin=10 * mm,
                topMargin=28 * mm,  # Zwiększony margines górny, aby obniżyć początek tabeli
                bottomMargin=15 * mm
            )
            
            # Stylizacja dokumentu w stylu "Bieg Krokodyla"
            styles = getSampleStyleSheet()
            
            # Pobierz aktualnie wybrane kolumny
            column_headers = [col["name"] for col in self.active_columns]
            
            # Przygotuj dane dla tabeli
            table_data = [column_headers]  # Pierwszy wiersz to nagłówki
            
            # Wybierz dane do wyświetlenia (z filtrowania lub wszystkie)
            display_athletes = self.filtered_athletes if self.filtered_athletes else self.athletes
            
            # Pobierz wartości dla każdego sportowca
            for athlete in display_athletes:
                row_values = []
                
                for j, col_var in enumerate(self.column_vars):
                    # Pobierz aktualnie wybrane pole API dla tej kolumny
                    api_field = col_var.get()
                    
                    # Obsłuż specjalne przypadki
                    if api_field == "full_name" or api_field == "athlete_last_name,athlete_first_name":
                        # Połącz nazwisko i imię
                        last_name = athlete.get('athlete_last_name', '')
                        first_name = athlete.get('athlete_first_name', '')
                        if last_name and first_name:
                            value = f"{last_name} {first_name}"
                        else:
                            value = last_name or first_name or ""
                    elif "," in api_field:
                        # Obsługa wielokrotnych pól oddzielonych przecinkiem
                        fields = api_field.split(",")
                        values = []
                        for field in fields:
                            field_value = athlete.get(field, "")
                            if field_value:
                                values.append(str(field_value))
                        value = " ".join(values)
                    else:
                        # Standardowe pole - sprawdź czy istnieje w danych zawodnika
                        value = athlete.get(api_field, "")
                    
                    row_values.append(str(value))
                
                table_data.append(row_values)
                
            # Przygotuj dane tabeli - przetwórz nagłówki i dane
            processed_table_data = []
            
            # Format nagłówków tabeli - pierwszy wiersz
            headers_row = []
            for header in column_headers:
                if " " in header:
                    # Podziel nagłówki zawierające spację na dwie linie
                    parts = header.split(" ", 1)
                    header_text = f"{parts[0]}<br/>{parts[1]}"
                else:
                    header_text = header
                    
                # Tworzenie paragrafów dla nagłówków z czcionką DejaVuSans
                header_style = ParagraphStyle(
                    name='Header',
                    fontName='DejaVuSans-Bold',
                    fontSize=8,
                    leading=9,
                    alignment=1,
                    textColor=colors.whitesmoke
                )
                headers_row.append(Paragraph(header_text, header_style))
            
            processed_table_data.append(headers_row)
            
            # Wiersze danych z czcionką DejaVuSans dla polskich znaków
            for i, row in enumerate(table_data[1:]):
                row_style = ParagraphStyle(
                    name=f'Row{i}',
                    fontName='DejaVuSans',
                    fontSize=8, 
                    leading=9,
                    alignment=1
                )
                
                row_cells = []
                for cell in row:
                    # Formatowanie komórek
                    cell_text = str(cell).strip()
                    row_cells.append(Paragraph(cell_text, row_style))
                
                processed_table_data.append(row_cells)
            
            # Analizuj dane, aby określić szerokości kolumn
            column_widths = self.calculate_column_widths(column_headers, table_data[1:], doc.width)
            
            # Tworzenie tabeli
            table = Table(processed_table_data, colWidths=column_widths, repeatRows=1)
            
            # Stylizacja tabeli - z pomarańczowym nagłówkiem i bez linii pionowych
            table_style = TableStyle([
                # Nagłówek - pomarańczowy (oryginalny)
                ('BACKGROUND', (0, 0), (-1, 0), colors.HexColor('#FF6600')),  # Oryginalny pomarańczowy nagłówek
                ('TEXTCOLOR', (0, 0), (-1, 0), colors.whitesmoke),
                
                # Zawartość tabeli
                ('ALIGN', (0, 0), (-1, -1), 'CENTER'),
                ('VALIGN', (0, 0), (-1, -1), 'MIDDLE'),
                
                # Czcionki
                ('FONTNAME', (0, 0), (-1, -1), 'DejaVuSans'),  # Używaj DejaVuSans dla polskich znaków
                ('FONTNAME', (0, 0), (-1, 0), 'DejaVuSans-Bold'),  # Używaj wersji bold dla nagłówków
                
                # Odstępy w komórkach - większe dla czytelności
                ('TOPPADDING', (0, 0), (-1, -1), 3),
                ('BOTTOMPADDING', (0, 0), (-1, -1), 3),
                ('LEFTPADDING', (0, 0), (-1, -1), 2),
                ('RIGHTPADDING', (0, 0), (-1, -1), 2),
                
                # Obramowania - tylko linie poziome
                ('LINEBELOW', (0, 0), (-1, 0), 1, colors.black),  # Linia pod nagłówkiem
                ('LINEABOVE', (0, 1), (-1, -1), 0.5, colors.grey),  # Linie nad wierszami danych
                ('LINEBELOW', (0, 0), (-1, -1), 0.5, colors.grey),  # Linie pod wszystkimi wierszami
                
                # Górne i dolne obramowanie całej tabeli
                ('LINEABOVE', (0, 0), (-1, 0), 1, colors.black),
                ('LINEBELOW', (0, -1), (-1, -1), 1, colors.black),
            ])
            
            # Naprzemienne kolory wierszy
            for i in range(1, len(processed_table_data), 2):
                table_style.add('BACKGROUND', (0, i), (-1, i), colors.lightgrey)
            
            table.setStyle(table_style)
            
    # Tworzę funkcję do dodawania nagłówków i stopek z danymi, które będą używane przez doc.build
            def add_header_footer(canvas, doc):
                """Dodaje nagłówek i stopkę do dokumentu PDF"""
                canvas.saveState()
                
                # Definicja kolorów
                title_color = colors.HexColor('#FF6600')  # Pomarańczowy kolor dla nazwy biegu
                subtitle_color = colors.black  # Czarny kolor dla podtytułu
                
                # Przygotowanie nagłówka z czcionką DejaVuSans dla polskich znaków
                canvas.setFont('DejaVuSans-Bold', 14)
                canvas.setFillColor(title_color)
                
                # Tytuł zawodów
                title = self.race_name.get().upper()
                canvas.drawString(doc.leftMargin, doc.height + doc.topMargin - 15, title)
                
                # Podtytuł: miejscowość i data (z separatorami dla większej czytelności)
                canvas.setFont('DejaVuSans', 11)
                canvas.setFillColor(subtitle_color)
                subtitle = f"Wyniki OPEN | {self.race_distance.get()} | {self.race_location.get()} | {self.race_date.get()}"
                canvas.drawString(doc.leftMargin, doc.height + doc.topMargin - 30, subtitle)
                
                # Logo 1 (prawe) - używamy wybranej ścieżki
                if self.api_client.logo1_path and os.path.exists(self.api_client.logo1_path):
                    try:
                        logo1 = Image(self.api_client.logo1_path, width=80, height=35)
                        logo1.drawOn(canvas, doc.width + doc.leftMargin - 85, doc.height + doc.topMargin - 40)
                    except Exception as e:
                        print(f"Błąd podczas dodawania logo 1: {e}")
                
                # Logo 2 (lewe) - używamy wybranej ścieżki
                if self.api_client.logo2_path and os.path.exists(self.api_client.logo2_path):
                    try:
                        logo2 = Image(self.api_client.logo2_path, width=80, height=35)
                        logo2.drawOn(canvas, doc.width + doc.leftMargin - 170, doc.height + doc.topMargin - 40)
                    except Exception as e:
                        print(f"Błąd podczas dodawania logo 2: {e}")
                
                # Stopka - uproszczona zgodnie z wytycznymi
                canvas.setFont('DejaVuSans', 7)
                canvas.setFillColor(colors.black)
                
                # Tekst stopki
                footer_text = "Wygenerował: YO&GO Events - Twój pomiar czasu www.yogoevents.pl"
                canvas.drawString(doc.leftMargin, 15, footer_text)
                
                # Numer strony
                page_number = f"Strona {doc.page}"
                canvas.drawRightString(doc.width + doc.leftMargin, 15, page_number)
                
                # Logo w środku stopki (używamy logo 1 jeśli wybrane)
                if self.api_client.logo1_path and os.path.exists(self.api_client.logo1_path):
                    try:
                        logo_stopka = Image(self.api_client.logo1_path, width=25, height=12)
                        logo_stopka.drawOn(canvas, (doc.width/2 + doc.leftMargin - 12), 11)
                    except Exception as e:
                        print(f"Błąd podczas dodawania logo do stopki: {e}")
                
                canvas.restoreState()
            
            # Buduj dokument z nagłówkiem i stopką
            doc.build([table], onFirstPage=add_header_footer, onLaterPages=add_header_footer)
            
            # Poinformuj użytkownika o wygenerowaniu pliku
            self.update_loading_label("")
            self.status_var.set(f"Wygenerowano plik PDF: {filename}")
            
            # Zapytaj, czy otworzyć plik
            if messagebox.askyesno("PDF wygenerowany", f"Plik PDF został zapisany w {filename}. Czy chcesz go otworzyć?"):
                # Otwórz plik w domyślnym programie
                if sys.platform == 'darwin':  # macOS
                    os.system(f"open {filename}")
                elif sys.platform == 'win32':  # Windows
                    os.system(f'start "" "{filename}"')
                else:  # Linux/Unix
                    os.system(f"xdg-open {filename}")
        except Exception as e:
            self.update_loading_label("")
            self.status_var.set("Błąd generowania PDF")
            messagebox.showerror("Błąd", f"Wystąpił błąd podczas generowania pliku PDF: {str(e)}")
            import traceback
            traceback.print_exc()
    
    def calculate_column_widths(self, headers, rows, available_width):
        """Oblicza optymalne szerokości kolumn w stylu dokumentu "Bieg Krokodyla" """
        num_columns = len(headers)
        
        # Bazowe proporcje szerokości kolumn - na wzór dokumentu "Bieg Krokodyla"
        # Różne typy kolumn mają różne proporcje szerokości
        base_proportions = []
        
        for header in headers:
            header_lower = header.lower()
            
            # Kolumny z numerami i miejscami - wąskie
            if any(x in header_lower for x in ["msc", "mce", "nr", "start", "kat"]):
                base_proportions.append(0.5)
            
            # Kolumny z czasami i tempem - średnie
            elif any(x in header_lower for x in ["czas", "tempo", "min", "km", "brutto", "netto"]):
                base_proportions.append(0.9)
            
            # Kolumny z nazwiskami - szersze
            elif "nazwisko" in header_lower or "imię" in header_lower or "imie" in header_lower:
                base_proportions.append(1.5)
            
            # Kolumny z klubami i miejscowościami - szersze
            elif "miejscowość" in header_lower or "klub" in header_lower:
                base_proportions.append(1.4)
            
            # Kolumny z rokiem urodzenia - wąskie
            elif "rok" in header_lower:
                base_proportions.append(0.6)
            
            # Kolumny z karami - wąskie
            elif "kary" in header_lower:
                base_proportions.append(0.6)
            
            # Domyślna proporcja
            else:
                base_proportions.append(1.0)
        
        # Znormalizuj proporcje
        total_proportion = sum(base_proportions)
        normalized_proportions = [p / total_proportion for p in base_proportions]
        
        # Oblicz szerokości kolumn
        column_widths = [prop * available_width for prop in normalized_proportions]
        
        # Zapewnij minimalne szerokości dla wszystkich kolumn
        min_widths = [20, 20, 50, 40, 40, 20, 20, 20, 20, 30, 30, 30, 30]  # Minimalne szerokości dla każdego typu kolumny
        if len(min_widths) < num_columns:
            min_widths.extend([25] * (num_columns - len(min_widths)))
            
        for i in range(num_columns):
            column_widths[i] = max(column_widths[i], min_widths[i])
        
        # Upewnij się, że całkowita szerokość mieści się w dostępnej przestrzeni
        if sum(column_widths) > available_width:
            scale = available_width / sum(column_widths)
            column_widths = [w * scale for w in column_widths]
        
        return column_widths

    def add_header_footer(self, canvas, doc):
        """Dodaje nagłówek i stopkę w stylu dokumentu z ostatnimi poprawkami"""
        canvas.saveState()
        
        # Definicja kolorów
        title_color = colors.HexColor('#FF6600')  # Pomarańczowy kolor dla nazwy biegu
        subtitle_color = colors.black  # Czarny kolor dla podtytułu
        
        # Przygotowanie nagłówka
        canvas.setFont('Helvetica-Bold', 14)
        canvas.setFillColor(title_color)
        
        # Tytuł zawodów
        title = self.race_name.get().upper()
        canvas.drawString(doc.leftMargin, doc.height + doc.topMargin - 15, title)
        
        # Podtytuł: miejscowość i data (z separatorami dla większej czytelności)
        canvas.setFont('Helvetica', 11)
        canvas.setFillColor(subtitle_color)
        subtitle = f"Wyniki OPEN | {self.race_distance.get()} | {self.race_location.get()} | {self.race_date.get()}"
        canvas.drawString(doc.leftMargin, doc.height + doc.topMargin - 30, subtitle)
        
        # Logo 1 (prawe) - używamy wybranej ścieżki
        if self.api_client.logo1_path and os.path.exists(self.api_client.logo1_path):
            try:
                logo1 = Image(self.api_client.logo1_path, width=80, height=35)
                logo1.drawOn(canvas, doc.width + doc.leftMargin - 85, doc.height + doc.topMargin - 40)
            except Exception as e:
                print(f"Błąd podczas dodawania logo 1: {e}")
        
        # Logo 2 (lewe) - używamy wybranej ścieżki
        if self.api_client.logo2_path and os.path.exists(self.api_client.logo2_path):
            try:
                logo2 = Image(self.api_client.logo2_path, width=80, height=35)
                logo2.drawOn(canvas, doc.width + doc.leftMargin - 170, doc.height + doc.topMargin - 40)
            except Exception as e:
                print(f"Błąd podczas dodawania logo 2: {e}")
        
        # Stopka - uproszczona zgodnie z wytycznymi
        canvas.setFont('Helvetica', 7)
        canvas.setFillColor(colors.black)
        
        # Tekst stopki
        footer_text = "Wygenerował: YO&GO Events - Twój pomiar czasu www.yogoevents.pl"
        canvas.drawString(doc.leftMargin, 15, footer_text)
        
        # Numer strony
        page_number = f"Strona {doc.page}"
        canvas.drawRightString(doc.width + doc.leftMargin, 15, page_number)
        
        # Logo w środku stopki (używamy logo 1 jeśli wybrane)
        if self.api_client.logo1_path and os.path.exists(self.api_client.logo1_path):
            try:
                logo_stopka = Image(self.api_client.logo1_path, width=25, height=12)
                logo_stopka.drawOn(canvas, (doc.width/2 + doc.leftMargin - 12), 11)
            except Exception as e:
                print(f"Błąd podczas dodawania logo do stopki: {e}")
        
        canvas.restoreState()

# Uruchomienie aplikacji
if __name__ == "__main__":
    root = tk.Tk()
    app = ResultsGeneratorApp(root)
    root.mainloop()
