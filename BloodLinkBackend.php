<?php
/**
 * Blood Link — PHP Backend API
 * Laravel-style MVC structure
 *
 * File layout (place in Laravel project root):
 *   routes/api.php          ← API route definitions
 *   app/Http/Controllers/   ← Controllers
 *   app/Models/             ← Eloquent models
 *   app/Services/           ← Business logic
 *
 * Requires: PHP 8.1+, Laravel 10+, tymon/jwt-auth
 * Install:  composer require tymon/jwt-auth
 *           php artisan vendor:publish --provider="Tymon\JWTAuth\Providers\LaravelServiceProvider"
 *           php artisan jwt:secret
 */

// ─────────────────────────────────────────────────────────────
// FILE: routes/api.php
// ─────────────────────────────────────────────────────────────
/*
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DonorController;
use App\Http\Controllers\HospitalController;
use App\Http\Controllers\BloodRequestController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\AdminController;

// Public routes
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login',    [AuthController::class, 'login']);

// Protected routes (JWT middleware)
Route::middleware('auth:api')->group(function () {

    // Auth
    Route::post('/auth/logout',  [AuthController::class, 'logout']);
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);
    Route::get('/auth/me',       [AuthController::class, 'me']);

    // Donor routes
    Route::prefix('donor')->group(function () {
        Route::get('/profile',               [DonorController::class, 'profile']);
        Route::put('/profile',               [DonorController::class, 'updateProfile']);
        Route::put('/health',                [DonorController::class, 'updateHealth']);
        Route::get('/eligibility',           [DonorController::class, 'getEligibility']);
        Route::get('/donations',             [DonorController::class, 'donationHistory']);
        Route::get('/notifications',         [DonorController::class, 'notifications']);
        Route::post('/notifications/{id}/read', [DonorController::class, 'markRead']);
        Route::get('/requests/nearby',       [DonorController::class, 'nearbyRequests']);
        Route::post('/requests/{id}/respond',[DonorController::class, 'respondToRequest']);
    });

    // Hospital routes
    Route::prefix('hospital')->group(function () {
        Route::get('/profile',               [HospitalController::class, 'profile']);
        Route::put('/profile',               [HospitalController::class, 'update']);
        Route::get('/requests',              [BloodRequestController::class, 'index']);
        Route::post('/requests',             [BloodRequestController::class, 'store']);
        Route::get('/requests/{id}',         [BloodRequestController::class, 'show']);
        Route::put('/requests/{id}',         [BloodRequestController::class, 'update']);
        Route::delete('/requests/{id}',      [BloodRequestController::class, 'cancel']);
        Route::get('/requests/{id}/matches', [BloodRequestController::class, 'getMatches']);
        Route::post('/requests/{id}/notify', [BloodRequestController::class, 'notifyDonors']);
        Route::get('/inventory',             [InventoryController::class, 'show']);
        Route::put('/inventory',             [InventoryController::class, 'update']);
    });

    // Admin routes
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::get('/stats',                 [AdminController::class, 'systemStats']);
        Route::get('/donors',                [AdminController::class, 'listDonors']);
        Route::get('/donors/{id}',           [AdminController::class, 'getDonor']);
        Route::put('/donors/{id}/status',    [AdminController::class, 'updateDonorStatus']);
        Route::get('/hospitals',             [AdminController::class, 'listHospitals']);
        Route::put('/hospitals/{id}/verify', [AdminController::class, 'verifyHospital']);
        Route::get('/requests',              [AdminController::class, 'allRequests']);
        Route::get('/audit-logs',            [AdminController::class, 'auditLogs']);
        Route::get('/reports/monthly',       [AdminController::class, 'monthlyReport']);
        Route::post('/ml/retrain',           [AdminController::class, 'retrainModel']);
    });
});
*/

// ─────────────────────────────────────────────────────────────
// FILE: app/Http/Controllers/AuthController.php
// ─────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Donor;
use App\Models\Hospital;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends BaseController
{
    /**
     * POST /api/auth/register
     * Register a new donor or hospital account.
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'       => 'required|string|max:100',
            'email'      => 'required|email|unique:users,email',
            'password'   => 'required|min:8|confirmed',
            'role'       => 'required|in:donor,hospital',
            'phone'      => 'nullable|string|max:25',
            // Donor-specific
            'blood_type' => 'required_if:role,donor|in:A+,A-,B+,B-,AB+,AB-,O+,O-',
            'gender'     => 'required_if:role,donor|in:male,female,other',
            'date_of_birth' => 'required_if:role,donor|date',
            'weight_kg'  => 'required_if:role,donor|numeric|min:30|max:300',
            'hemoglobin' => 'required_if:role,donor|numeric|min:5|max:25',
            // Hospital-specific
            'hospital_name' => 'required_if:role,hospital|string|max:150',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors(), 422);
        }

        // Create user
        $user = User::create([
            'name'          => $request->name,
            'email'         => $request->email,
            'password_hash' => Hash::make($request->password),
            'role'          => $request->role,
            'phone'         => $request->phone,
        ]);

        // Create role-specific profile
        if ($request->role === 'donor') {
            $donor = Donor::create([
                'user_id'        => $user->id,
                'blood_type'     => $request->blood_type,
                'gender'         => $request->gender,
                'date_of_birth'  => $request->date_of_birth,
                'weight_kg'      => $request->weight_kg,
                'hemoglobin_gdl' => $request->hemoglobin,
                'address'        => $request->address,
                'city'           => $request->city,
                'latitude'       => $request->latitude,
                'longitude'      => $request->longitude,
                'last_donation_date' => $request->last_donation_date,
                'total_donations'    => 0,
            ]);

            // Run AI eligibility screen
            $eligibility = app(EligibilityService::class)->screen($donor, $request->medical_conditions ?? []);
            $donor->update([
                'is_eligible'            => $eligibility['eligible'],
                'eligibility_score'      => $eligibility['score'],
                'eligibility_confidence' => $eligibility['confidence'],
                'eligibility_checked_at' => now(),
                'eligibility_reasons'    => json_encode($eligibility['reasons']),
            ]);

            // Save medical conditions
            if (!empty($request->medical_conditions)) {
                foreach ($request->medical_conditions as $condition) {
                    $donor->conditions()->create(['condition' => $condition]);
                }
            }
        } elseif ($request->role === 'hospital') {
            Hospital::create([
                'user_id'   => $user->id,
                'name'      => $request->hospital_name,
                'address'   => $request->address,
                'city'      => $request->city,
                'latitude'  => $request->latitude,
                'longitude' => $request->longitude,
                'contact'   => $request->contact,
            ]);
        }

        $token = JWTAuth::fromUser($user);
        return $this->successResponse([
            'token' => $token,
            'user'  => $user->load($request->role),
        ], 'Registration successful', 201);
    }

    /**
     * POST /api/auth/login
     */
    public function login(Request $request)
    {
        $credentials = $request->only('email', 'password');

        if (!$token = auth('api')->attempt([
            'email'    => $credentials['email'],
            'password' => $credentials['password'],
        ])) {
            return $this->errorResponse('Invalid credentials', 401);
        }

        $user = auth('api')->user();
        return $this->successResponse([
            'token'      => $token,
            'token_type' => 'Bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user'       => $user,
            'role'       => $user->role,
        ]);
    }

    public function logout()
    {
        auth('api')->logout();
        return $this->successResponse([], 'Logged out successfully');
    }

    public function refresh()
    {
        return $this->successResponse([
            'token' => auth('api')->refresh(),
        ]);
    }

    public function me()
    {
        return $this->successResponse(auth('api')->user());
    }
}


// ─────────────────────────────────────────────────────────────
// FILE: app/Http/Controllers/DonorController.php
// ─────────────────────────────────────────────────────────────

class DonorController extends BaseController
{
    protected EligibilityService $eligibilityService;

    public function __construct(EligibilityService $eligibilityService)
    {
        $this->eligibilityService = $eligibilityService;
    }

    public function profile()
    {
        $donor = auth()->user()->donor()->with(['conditions', 'donations'])->firstOrFail();
        return $this->successResponse($donor);
    }

    /**
     * PUT /api/donor/health
     * Update health parameters and re-run AI eligibility screen.
     */
    public function updateHealth(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'weight_kg'         => 'sometimes|numeric|min:30',
            'hemoglobin_gdl'    => 'sometimes|numeric|min:5|max:25',
            'last_donation_date'=> 'sometimes|date',
            'medical_conditions'=> 'sometimes|array',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors(), 422);
        }

        $donor = auth()->user()->donor;
        $donor->update($request->only(['weight_kg', 'hemoglobin_gdl', 'last_donation_date']));

        // Update medical conditions
        if ($request->has('medical_conditions')) {
            $donor->conditions()->delete();
            foreach ($request->medical_conditions as $cond) {
                $donor->conditions()->create(['condition' => $cond]);
            }
        }

        // Re-run AI eligibility screen
        $eligibility = $this->eligibilityService->screen($donor);
        $donor->update([
            'is_eligible'            => $eligibility['eligible'],
            'eligibility_score'      => $eligibility['score'],
            'eligibility_confidence' => $eligibility['confidence'],
            'eligibility_checked_at' => now(),
            'eligibility_reasons'    => json_encode($eligibility['reasons']),
        ]);

        return $this->successResponse([
            'donor'       => $donor->fresh(),
            'eligibility' => $eligibility,
        ], 'Health updated and AI re-assessment complete');
    }

    public function nearbyRequests(Request $request)
    {
        $donor = auth()->user()->donor;
        if (!$donor->latitude || !$donor->longitude) {
            return $this->errorResponse('Location not set on donor profile', 400);
        }

        $radiusKm = $request->get('radius', 25);

        // Haversine formula in SQL to find nearby open requests
        $requests = \DB::table('blood_requests as br')
            ->join('hospitals as h', 'h.id', '=', 'br.hospital_id')
            ->select([
                'br.*', 'h.name as hospital_name',
                \DB::raw("(6371 * ACOS(COS(RADIANS({$donor->latitude})) * COS(RADIANS(h.latitude)) * 
                    COS(RADIANS(h.longitude) - RADIANS({$donor->longitude})) + 
                    SIN(RADIANS({$donor->latitude})) * SIN(RADIANS(h.latitude)))) AS distance_km")
            ])
            ->where('br.status', 'open')
            ->whereIn('br.blood_type', $this->getCompatibleTypes($donor->blood_type))
            ->having('distance_km', '<=', $radiusKm)
            ->orderBy('distance_km')
            ->orderByRaw("FIELD(br.urgency, 'critical', 'urgent', 'moderate', 'scheduled')")
            ->get();

        return $this->successResponse($requests);
    }

    private function getCompatibleTypes(string $donorType): array
    {
        // What request types a donor with $donorType can fulfill
        $matrix = [
            'O-'  => ['O-','O+','A-','A+','B-','B+','AB-','AB+'],
            'O+'  => ['O+','A+','B+','AB+'],
            'A-'  => ['A-','A+','AB-','AB+'],
            'A+'  => ['A+','AB+'],
            'B-'  => ['B-','B+','AB-','AB+'],
            'B+'  => ['B+','AB+'],
            'AB-' => ['AB-','AB+'],
            'AB+' => ['AB+'],
        ];
        return $matrix[$donorType] ?? [$donorType];
    }
}


// ─────────────────────────────────────────────────────────────
// FILE: app/Http/Controllers/BloodRequestController.php
// ─────────────────────────────────────────────────────────────

class BloodRequestController extends BaseController
{
    protected MatchingService $matchingService;
    protected NotificationService $notificationService;

    public function __construct(
        MatchingService $matchingService,
        NotificationService $notificationService
    ) {
        $this->matchingService     = $matchingService;
        $this->notificationService = $notificationService;
    }

    /**
     * POST /api/hospital/requests
     * Create a new blood request and immediately run AI matching.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'blood_type'   => 'required|in:A+,A-,B+,B-,AB+,AB-,O+,O-',
            'units_needed' => 'required|integer|min:1|max:50',
            'urgency'      => 'required|in:critical,urgent,moderate,scheduled',
            'department'   => 'nullable|string|max:100',
            'notes'        => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors(), 422);
        }

        $hospital = auth()->user()->hospital;

        $bloodRequest = \App\Models\BloodRequest::create([
            'hospital_id'  => $hospital->id,
            'blood_type'   => $request->blood_type,
            'units_needed' => $request->units_needed,
            'urgency'      => $request->urgency,
            'department'   => $request->department,
            'notes'        => $request->notes,
            'status'       => 'open',
            'created_by'   => auth()->id(),
            'expires_at'   => $this->getExpiryByUrgency($request->urgency),
        ]);

        // Run AI matching
        $matches = $this->matchingService->findMatches($bloodRequest, $hospital);

        // Store top matches
        foreach ($matches as $match) {
            \App\Models\DonorMatch::create([
                'request_id'  => $bloodRequest->id,
                'donor_id'    => $match['donor_id'],
                'match_score' => $match['match_score'],
                'distance_km' => $match['distance_km'],
                'blood_compat'=> $match['blood_compat'],
            ]);
        }

        // Auto-notify for critical urgency
        if ($request->urgency === 'critical') {
            $this->notificationService->notifyTopMatches($bloodRequest, $matches, 5);
        }

        return $this->successResponse([
            'request' => $bloodRequest,
            'matches_found' => count($matches),
            'top_matches' => array_slice($matches, 0, 10),
        ], 'Blood request created and AI matching complete', 201);
    }

    public function getMatches($requestId)
    {
        $bloodRequest = \App\Models\BloodRequest::where('hospital_id', auth()->user()->hospital->id)
            ->findOrFail($requestId);

        $matches = \App\Models\DonorMatch::where('request_id', $requestId)
            ->with('donor.user')
            ->orderByDesc('match_score')
            ->get();

        return $this->successResponse($matches);
    }

    public function notifyDonors(Request $request, $requestId)
    {
        $bloodRequest = \App\Models\BloodRequest::findOrFail($requestId);
        $donorIds     = $request->donor_ids ?? [];
        $channels     = $request->channels ?? ['email'];

        $notified = $this->notificationService->notifySpecificDonors(
            $bloodRequest, $donorIds, $channels
        );

        return $this->successResponse(['notified' => $notified], "{$notified} donors notified");
    }

    private function getExpiryByUrgency(string $urgency): string
    {
        return match($urgency) {
            'critical'  => now()->addHours(6)->toDateTimeString(),
            'urgent'    => now()->addHours(24)->toDateTimeString(),
            'moderate'  => now()->addHours(48)->toDateTimeString(),
            'scheduled' => now()->addDays(7)->toDateTimeString(),
        };
    }
}


// ─────────────────────────────────────────────────────────────
// FILE: app/Services/EligibilityService.php
// Calls the Python ML API for AI screening
// ─────────────────────────────────────────────────────────────

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EligibilityService
{
    private string $mlApiUrl;

    public function __construct()
    {
        $this->mlApiUrl = config('services.ml_api.url', 'http://localhost:5000');
    }

    /**
     * Screen a donor using the Python ML API.
     * Falls back to rule-based PHP screening if ML API is unavailable.
     */
    public function screen(\App\Models\Donor $donor, array $conditions = []): array
    {
        $payload = [
            'age'                       => $donor->age,
            'weight'                    => $donor->weight_kg,
            'hemoglobin'                => $donor->hemoglobin_gdl,
            'days_since_last_donation'  => $donor->daysSinceLastDonation(),
            'medical_conditions'        => $conditions ?: $donor->conditionNames(),
            'gender'                    => $donor->gender,
            'total_donations'           => $donor->total_donations,
        ];

        try {
            $response = Http::timeout(5)
                ->post("{$this->mlApiUrl}/api/screen", $payload);

            if ($response->successful()) {
                return $response->json();
            }
        } catch (\Exception $e) {
            Log::warning("ML API unavailable, using PHP fallback: {$e->getMessage()}");
        }

        // PHP fallback screening (mirrors Python logic)
        return $this->phpFallbackScreen($payload);
    }

    private function phpFallbackScreen(array $d): array
    {
        $score   = 100.0;
        $reasons = [];
        $warnings = [];

        if ($d['age'] < 18 || $d['age'] > 65) {
            $reasons[] = "Age {$d['age']} outside 18-65 range";
            $score -= 100;
        }
        if ($d['weight'] < 50) {
            $reasons[] = "Weight {$d['weight']} kg below 50 kg minimum";
            $score -= 40;
        }
        $hbMin = $d['gender'] === 'male' ? 13.0 : 12.5;
        if ($d['hemoglobin'] > 0 && $d['hemoglobin'] < $hbMin) {
            $reasons[] = "Hemoglobin {$d['hemoglobin']} g/dL below minimum {$hbMin}";
            $score -= 50;
        }
        if ($d['days_since_last_donation'] < 56 && $d['days_since_last_donation'] > 0) {
            $reasons[] = "Last donation {$d['days_since_last_donation']} days ago (min 56)";
            $score -= 60;
        }

        $blockers = ['hiv', 'hepatitis', 'heart_disease', 'cancer'];
        foreach ($blockers as $b) {
            if (in_array($b, $d['medical_conditions'])) {
                $reasons[] = "Disqualifying condition: {$b}";
                $score = 0;
            }
        }

        $score = max(0.0, min(100.0, $score));
        return [
            'eligible'   => $score >= 60 && empty($reasons),
            'confidence' => round(0.5 + abs($score - 50) / 100, 3),
            'score'      => round($score, 1),
            'reasons'    => $reasons,
            'warnings'   => $warnings,
        ];
    }
}


// ─────────────────────────────────────────────────────────────
// FILE: app/Services/MatchingService.php
// ─────────────────────────────────────────────────────────────

class MatchingService
{
    private string $mlApiUrl;

    public function __construct()
    {
        $this->mlApiUrl = config('services.ml_api.url', 'http://localhost:5000');
    }

    public function findMatches(\App\Models\BloodRequest $request, $hospital): array
    {
        // Fetch eligible donors with compatible blood types
        $compatible = $this->getCompatibleDonorTypes($request->blood_type);
        $donors = \App\Models\Donor::whereIn('blood_type', $compatible)
            ->where('is_eligible', true)
            ->where('is_available', true)
            ->with('user')
            ->get()
            ->map(fn($d) => [
                'id'                       => $d->id,
                'name'                     => $d->user->name,
                'blood_type'               => $d->blood_type,
                'lat'                      => (float) $d->latitude,
                'lng'                      => (float) $d->longitude,
                'days_since_last_donation' => $d->daysSinceLastDonation(),
                'total_donations'          => $d->total_donations,
                'email'                    => $d->user->email,
                'phone'                    => $d->user->phone,
            ])
            ->toArray();

        $payload = [
            'request' => [
                'blood_type'   => $request->blood_type,
                'units_needed' => $request->units_needed,
                'urgency'      => $request->urgency,
                'hospital_lat' => (float) $hospital->latitude,
                'hospital_lng' => (float) $hospital->longitude,
            ],
            'donors' => $donors,
        ];

        try {
            $response = Http::timeout(10)
                ->post("{$this->mlApiUrl}/api/match", $payload);
            if ($response->successful()) {
                return $response->json('matches', []);
            }
        } catch (\Exception $e) {
            Log::warning("ML match API unavailable: {$e->getMessage()}");
        }

        // Fallback: simple distance-based sorting
        return collect($donors)
            ->map(function ($d) use ($hospital) {
                $dist = $this->haversine($hospital->latitude, $hospital->longitude, $d['lat'], $d['lng']);
                $d['match_score'] = max(0, 85 - $dist);
                $d['distance_km'] = round($dist, 2);
                $d['blood_compat'] = $d['blood_type'] === $hospital->blood_type ? 'exact' : 'compatible';
                return $d;
            })
            ->sortByDesc('match_score')
            ->values()
            ->toArray();
    }

    private function getCompatibleDonorTypes(string $requestType): array
    {
        return [
            'O-'  => ['O-'],
            'O+'  => ['O-','O+'],
            'A-'  => ['O-','A-'],
            'A+'  => ['O-','O+','A-','A+'],
            'B-'  => ['O-','B-'],
            'B+'  => ['O-','O+','B-','B+'],
            'AB-' => ['O-','A-','B-','AB-'],
            'AB+' => ['O-','O+','A-','A+','B-','B+','AB-','AB+'],
        ][$requestType] ?? [$requestType];
    }

    private function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $R = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat/2)**2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2)**2;
        return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
    }
}


// ─────────────────────────────────────────────────────────────
// FILE: app/Services/NotificationService.php
// ─────────────────────────────────────────────────────────────

class NotificationService
{
    public function notifyTopMatches(\App\Models\BloodRequest $request, array $matches, int $limit = 5): int
    {
        $notified = 0;
        foreach (array_slice($matches, 0, $limit) as $match) {
            $this->sendEmail($match['email'], $request);
            $this->sendSms($match['phone'],  $request);
            $this->createInAppNotification($match['id'], $request);
            \App\Models\DonorMatch::where('request_id', $request->id)
                ->where('donor_id', $match['id'])
                ->update(['notified_at' => now(), 'notify_channel' => 'email,sms']);
            $notified++;
        }
        return $notified;
    }

    private function sendEmail(string $email, \App\Models\BloodRequest $request): void
    {
        // Laravel Mail facade
        \Illuminate\Support\Facades\Mail::to($email)->queue(
            new \App\Mail\BloodRequestNotification($request)
        );
    }

    private function sendSms(?string $phone, \App\Models\BloodRequest $request): void
    {
        if (!$phone) return;
        // Twilio SDK
        try {
            $twilio = new \Twilio\Rest\Client(
                config('services.twilio.sid'),
                config('services.twilio.token')
            );
            $hospital = $request->hospital->name;
            $type     = $request->blood_type;
            $urgency  = strtoupper($request->urgency);
            $twilio->messages->create($phone, [
                'from' => config('services.twilio.from'),
                'body' => "[{$urgency}] {$hospital} needs {$type} blood. Log in to BloodLink to respond."
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning("SMS failed for {$phone}: {$e->getMessage()}");
        }
    }

    private function createInAppNotification(int $donorUserId, \App\Models\BloodRequest $request): void
    {
        \App\Models\Notification::create([
            'user_id' => \App\Models\Donor::find($donorUserId)->user_id,
            'type'    => 'match_request',
            'title'   => "Blood Request: {$request->blood_type} needed",
            'body'    => "{$request->hospital->name} needs {$request->units_needed} unit(s) of {$request->blood_type} ({$request->urgency}).",
            'channel' => 'in_app',
            'meta'    => json_encode(['request_id' => $request->id]),
        ]);
    }
}


// ─────────────────────────────────────────────────────────────
// FILE: app/Http/Controllers/BaseController.php
// ─────────────────────────────────────────────────────────────

class BaseController extends Controller
{
    protected function successResponse($data, string $message = 'Success', int $code = 200)
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $code);
    }

    protected function errorResponse($error, int $code = 400)
    {
        return response()->json([
            'success' => false,
            'error'   => $error,
        ], $code);
    }
}
