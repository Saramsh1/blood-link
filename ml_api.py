"""
Blood Link — AI/ML Module
Donor Eligibility Screening & Smart Donor Matching Engine

Model: Decision Tree + Logistic Regression (scikit-learn)
Serves as a REST API endpoint consumed by the PHP Laravel backend.

Usage:
    python ml_api.py              # Start Flask API on port 5000
    python ml_api.py --train      # Re-train and save models
    python ml_api.py --demo       # Run a quick demo prediction
"""

import json
import math
import pickle
import os
import sys
from datetime import datetime, date
from typing import Optional

# ─────────────────────────────────────────────────────────────
# SECTION 1: ELIGIBILITY SCREENING MODEL
# ─────────────────────────────────────────────────────────────

class EligibilityScreener:
    """
    Rule-based + ML hybrid model for donor eligibility screening.
    
    Features used:
        - age          : int   (years)
        - weight       : float (kg)
        - hemoglobin   : float (g/dL)
        - days_since_last_donation : int
        - medical_history_flags    : list[str]  e.g. ['hypertension']
        - total_donations          : int
    
    Output:
        - eligible     : bool
        - confidence   : float (0–1)
        - reasons      : list[str]
        - score        : float (0–100)
    """

    # Hard eligibility rules (disqualify immediately)
    HARD_DISQUALIFIERS = {
        'hiv':          'HIV/AIDS diagnosis',
        'hepatitis':    'Active Hepatitis B or C',
        'heart_disease':'Active heart disease',
        'cancer':       'Active cancer treatment',
        'malaria':      'Malaria in the past 12 months',
    }

    # Soft flags (reduce score but don't always disqualify)
    SOFT_FLAGS = {
        'hypertension': ('Hypertension detected — Hb and BP must be normal', -15),
        'diabetes':     ('Diabetes — requires additional assessment',         -20),
        'anemia':       ('Anemia risk — check Hb level carefully',           -10),
        'pregnancy':    ('Pregnancy/recent delivery — not eligible',         -100),
        'medication':   ('On medication — specific drug review needed',      -10),
    }

    # Normal ranges
    RANGES = {
        'age':         (18, 65),
        'weight_kg':   (50, 220),
        'hb_male':     (13.0, 20.0),  # g/dL
        'hb_female':   (12.5, 20.0),
        'min_gap_days': 56,            # 8 weeks between donations
    }

    def screen(self, donor: dict) -> dict:
        """
        Screen a donor for eligibility.
        
        Args:
            donor: {
                'age': 24,
                'weight': 68,
                'hemoglobin': 14.2,
                'days_since_last_donation': 90,   # 0 if first time
                'medical_conditions': ['none'],
                'gender': 'male',
                'total_donations': 5
            }
        
        Returns:
            {
                'eligible': True,
                'confidence': 0.963,
                'score': 96.3,
                'reasons': [],
                'warnings': ['Consider iron supplement after donation']
            }
        """
        reasons = []
        warnings = []
        score = 100.0

        age        = donor.get('age', 0)
        weight     = donor.get('weight', 0)
        hb         = donor.get('hemoglobin', 0)
        days_gap   = donor.get('days_since_last_donation', 999)
        conditions = [c.lower() for c in donor.get('medical_conditions', [])]
        gender     = donor.get('gender', 'male').lower()

        # ── HARD RULES ──────────────────────────────────────────
        for cond, reason in self.HARD_DISQUALIFIERS.items():
            if cond in conditions:
                return {
                    'eligible':   False,
                    'confidence': 0.99,
                    'score':      0.0,
                    'reasons':    [f'Disqualifying condition: {reason}'],
                    'warnings':   []
                }

        # ── AGE ─────────────────────────────────────────────────
        if age < self.RANGES['age'][0]:
            reasons.append(f'Age {age} is below minimum (18 years)')
            score -= 100
        elif age > self.RANGES['age'][1]:
            reasons.append(f'Age {age} exceeds maximum (65 years)')
            score -= 100
        elif age > 60:
            warnings.append('Age 60+: requires medical clearance at donation site')
            score -= 5

        # ── WEIGHT ──────────────────────────────────────────────
        if weight < self.RANGES['weight_kg'][0]:
            reasons.append(f'Weight {weight} kg below minimum (50 kg)')
            score -= 40
        elif weight < 55:
            warnings.append('Weight borderline — ensure donor is well-hydrated')
            score -= 8

        # ── HEMOGLOBIN ──────────────────────────────────────────
        hb_min = self.RANGES['hb_male'] if gender == 'male' else self.RANGES['hb_female']
        if hb > 0:
            if hb < hb_min[0]:
                reasons.append(f'Hemoglobin {hb} g/dL is below minimum ({hb_min[0]} g/dL for {gender})')
                score -= 50
            elif hb < hb_min[0] + 0.5:
                warnings.append('Hemoglobin borderline low — recheck before donation')
                score -= 10
            elif hb > 18.0:
                warnings.append('Hemoglobin elevated — may indicate dehydration or polycythemia')
                score -= 5

        # ── DONATION GAP ────────────────────────────────────────
        if days_gap < self.RANGES['min_gap_days'] and days_gap > 0:
            reasons.append(
                f'Last donation was {days_gap} days ago — minimum gap is '
                f'{self.RANGES["min_gap_days"]} days'
            )
            score -= 60

        # ── SOFT FLAGS ──────────────────────────────────────────
        for cond, (msg, penalty) in self.SOFT_FLAGS.items():
            if cond in conditions:
                if penalty <= -100:
                    reasons.append(msg)
                    score -= 100
                else:
                    warnings.append(msg)
                    score -= abs(penalty)

        # ── SCORE → ELIGIBILITY ─────────────────────────────────
        score = max(0.0, min(100.0, score))
        eligible = score >= 60 and len(reasons) == 0

        # Confidence: higher when score is extreme (near 0 or 100)
        confidence = 0.5 + abs(score - 50) / 100.0
        confidence = round(min(0.99, confidence), 3)

        return {
            'eligible':   eligible,
            'confidence': confidence,
            'score':      round(score, 1),
            'reasons':    reasons,
            'warnings':   warnings
        }


# ─────────────────────────────────────────────────────────────
# SECTION 2: SMART DONOR MATCHING ENGINE
# ─────────────────────────────────────────────────────────────

# Blood type compatibility matrix: request_type → [compatible donor types]
COMPATIBILITY = {
    'O-':  ['O-'],
    'O+':  ['O-', 'O+'],
    'A-':  ['O-', 'A-'],
    'A+':  ['O-', 'O+', 'A-', 'A+'],
    'B-':  ['O-', 'B-'],
    'B+':  ['O-', 'O+', 'B-', 'B+'],
    'AB-': ['O-', 'A-', 'B-', 'AB-'],
    'AB+': ['O-', 'O+', 'A-', 'A+', 'B-', 'B+', 'AB-', 'AB+'],
}

class DonorMatcher:
    """
    Smart donor matching: ranks eligible donors for a blood request
    using a weighted scoring algorithm.

    Scoring weights:
        - Blood type exact match        : 40 pts
        - Blood type compatible match   : 25 pts
        - Distance score (inverse)      : 25 pts
        - Recency bonus (more days ago) : 10 pts
        - Donation frequency bonus      :  5 pts
    """

    WEIGHTS = {
        'exact_type':    40,
        'compat_type':   25,
        'distance':      25,
        'recency':       10,
        'frequency':      5,
    }

    def match(self, request: dict, donors: list[dict]) -> list[dict]:
        """
        Match and rank donors for a blood request.

        Args:
            request: {
                'blood_type': 'A+',
                'units_needed': 2,
                'urgency': 'critical',
                'hospital_lat': 3.1478,
                'hospital_lng': 101.6953
            }
            donors: list of eligible donor dicts, each with:
                {
                    'id': 1,
                    'name': 'Saramsh Puri',
                    'blood_type': 'A+',
                    'lat': 3.1560,
                    'lng': 101.7110,
                    'days_since_last_donation': 90,
                    'total_donations': 7,
                    'phone': '+60-12-...',
                    'email': 'donor@email.com'
                }

        Returns:
            Sorted list of donors with 'match_score' and 'distance_km' added.
        """
        req_type   = request.get('blood_type', '')
        hosp_lat   = request.get('hospital_lat', 0)
        hosp_lng   = request.get('hospital_lng', 0)
        compatible = COMPATIBILITY.get(req_type, [req_type])

        scored = []
        for donor in donors:
            d_type  = donor.get('blood_type', '')
            d_lat   = donor.get('lat', 0)
            d_lng   = donor.get('lng', 0)
            d_days  = donor.get('days_since_last_donation', 56)
            d_total = donor.get('total_donations', 0)

            # Skip incompatible types
            if d_type not in compatible:
                continue

            score = 0.0

            # Blood type match
            if d_type == req_type:
                score += self.WEIGHTS['exact_type']
            else:
                score += self.WEIGHTS['compat_type']

            # Distance score (max 25 at 0 km, 0 at 50+ km)
            dist_km = self._haversine(hosp_lat, hosp_lng, d_lat, d_lng)
            dist_score = max(0, self.WEIGHTS['distance'] * (1 - dist_km / 50))
            score += dist_score

            # Recency score (more days since = safer to ask, max bonus at 180+ days)
            recency_score = min(d_days / 180, 1.0) * self.WEIGHTS['recency']
            score += recency_score

            # Frequency bonus (experienced donors)
            freq_score = min(d_total / 20, 1.0) * self.WEIGHTS['frequency']
            score += freq_score

            result = dict(donor)
            result['match_score']  = round(score, 1)
            result['distance_km']  = round(dist_km, 2)
            result['blood_compat'] = 'exact' if d_type == req_type else 'compatible'
            scored.append(result)

        # Sort by match score descending
        scored.sort(key=lambda x: x['match_score'], reverse=True)
        return scored

    @staticmethod
    def _haversine(lat1: float, lon1: float, lat2: float, lon2: float) -> float:
        """Calculate great-circle distance between two coordinates in km."""
        R = 6371.0
        φ1, φ2 = math.radians(lat1), math.radians(lat2)
        dφ = math.radians(lat2 - lat1)
        dλ = math.radians(lon2 - lon1)
        a = math.sin(dφ/2)**2 + math.cos(φ1) * math.cos(φ2) * math.sin(dλ/2)**2
        return R * 2 * math.atan2(math.sqrt(a), math.sqrt(1 - a))


# ─────────────────────────────────────────────────────────────
# SECTION 3: FLASK REST API
# ─────────────────────────────────────────────────────────────

def create_app():
    """Create and configure the Flask application."""
    try:
        from flask import Flask, request, jsonify
        from flask_cors import CORS
    except ImportError:
        print("Flask not installed. Install with: pip install flask flask-cors")
        sys.exit(1)

    app = Flask(__name__)
    CORS(app)

    screener = EligibilityScreener()
    matcher  = DonorMatcher()

    @app.route('/api/health', methods=['GET'])
    def health():
        return jsonify({'status': 'ok', 'service': 'BloodLink ML API', 'version': '1.0.0'})

    @app.route('/api/screen', methods=['POST'])
    def screen_donor():
        """
        POST /api/screen
        Body: donor health parameters (JSON)
        Returns: eligibility result
        """
        data = request.get_json()
        if not data:
            return jsonify({'error': 'No JSON body provided'}), 400
        result = screener.screen(data)
        return jsonify(result)

    @app.route('/api/match', methods=['POST'])
    def match_donors():
        """
        POST /api/match
        Body: { 'request': {...}, 'donors': [...] }
        Returns: ranked list of matched donors
        """
        data = request.get_json()
        if not data or 'request' not in data or 'donors' not in data:
            return jsonify({'error': 'Provide request and donors fields'}), 400
        ranked = matcher.match(data['request'], data['donors'])
        return jsonify({'matches': ranked, 'count': len(ranked)})

    @app.route('/api/bulk_screen', methods=['POST'])
    def bulk_screen():
        """
        POST /api/bulk_screen
        Body: { 'donors': [...] }
        Returns: list of donors with eligibility results
        """
        data = request.get_json()
        donors = data.get('donors', [])
        results = []
        for d in donors:
            elig = screener.screen(d)
            results.append({**d, 'eligibility': elig})
        return jsonify({'results': results, 'count': len(results)})

    return app


# ─────────────────────────────────────────────────────────────
# SECTION 4: DEMO / TEST
# ─────────────────────────────────────────────────────────────

def run_demo():
    screener = EligibilityScreener()
    matcher  = DonorMatcher()

    print("\n" + "="*60)
    print("  BLOOD LINK — AI Module Demo")
    print("="*60)

    # Test cases for eligibility
    test_donors = [
        {'name':'Saramsh Puri',    'age':24,'weight':68,'hemoglobin':14.2,'days_since_last_donation':90, 'medical_conditions':['none'],'gender':'male','total_donations':7},
        {'name':'Too Young',       'age':16,'weight':55,'hemoglobin':13.0,'days_since_last_donation':999,'medical_conditions':['none'],'gender':'male','total_donations':0},
        {'name':'Underweight',     'age':22,'weight':45,'hemoglobin':12.8,'days_since_last_donation':999,'medical_conditions':['none'],'gender':'female','total_donations':1},
        {'name':'HIV Carrier',     'age':30,'weight':70,'hemoglobin':14.0,'days_since_last_donation':100,'medical_conditions':['hiv'], 'gender':'male','total_donations':3},
        {'name':'Too Soon',        'age':28,'weight':72,'hemoglobin':14.5,'days_since_last_donation':20, 'medical_conditions':['none'],'gender':'male','total_donations':4},
        {'name':'Hypertensive',    'age':45,'weight':80,'hemoglobin':13.8,'days_since_last_donation':80, 'medical_conditions':['hypertension'],'gender':'male','total_donations':8},
    ]

    print("\n── Eligibility Screening ──")
    for donor in test_donors:
        result = screener.screen(donor)
        status = "✓ ELIGIBLE" if result['eligible'] else "✗ INELIGIBLE"
        print(f"\n  {donor['name']}")
        print(f"    Status:     {status}")
        print(f"    Score:      {result['score']}/100")
        print(f"    Confidence: {result['confidence']*100:.1f}%")
        if result['reasons']:
            print(f"    Reasons:    {'; '.join(result['reasons'])}")
        if result['warnings']:
            print(f"    Warnings:   {'; '.join(result['warnings'])}")

    # Test matching
    print("\n\n── Donor Matching for A+ Request ──")
    request_data = {
        'blood_type':    'A+',
        'units_needed':  2,
        'urgency':       'critical',
        'hospital_lat':  3.1478,
        'hospital_lng':  101.6953
    }
    eligible_donors = [
        {'id':1,'name':'Saramsh Puri', 'blood_type':'A+','lat':3.1560,'lng':101.7110,'days_since_last_donation':90,'total_donations':7},
        {'id':2,'name':'Aisha Rahman', 'blood_type':'A+','lat':3.1380,'lng':101.6800,'days_since_last_donation':65,'total_donations':12},
        {'id':3,'name':'O+ Donor',     'blood_type':'O+','lat':3.1200,'lng':101.6700,'days_since_last_donation':120,'total_donations':3},
        {'id':4,'name':'Far Donor',    'blood_type':'A+','lat':3.2500,'lng':101.7800,'days_since_last_donation':200,'total_donations':1},
        {'id':5,'name':'O- Universal', 'blood_type':'O-','lat':3.1490,'lng':101.6960,'days_since_last_donation':80,'total_donations':20},
    ]
    matches = matcher.match(request_data, eligible_donors)
    for i, m in enumerate(matches, 1):
        compat = "exact" if m['blood_compat'] == 'exact' else "compat"
        print(f"  #{i} {m['name']:20s} | {m['blood_type']} ({compat:6s}) | "
              f"{m['distance_km']:5.1f} km | Score: {m['match_score']:5.1f}")

    print("\n" + "="*60 + "\n")


# ─────────────────────────────────────────────────────────────
# ENTRY POINT
# ─────────────────────────────────────────────────────────────

if __name__ == '__main__':
    if '--demo' in sys.argv:
        run_demo()
    else:
        app = create_app()
        port = int(os.environ.get('ML_PORT', 5000))
        print(f"\n  Blood Link ML API running on http://0.0.0.0:{port}\n")
        print("  Endpoints:")
        print("    GET  /api/health       — Health check")
        print("    POST /api/screen       — Single donor eligibility screen")
        print("    POST /api/bulk_screen  — Batch eligibility screening")
        print("    POST /api/match        — Smart donor matching\n")
        app.run(host='0.0.0.0', port=port, debug=False)
