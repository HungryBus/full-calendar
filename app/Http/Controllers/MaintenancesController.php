<?php

namespace App\Http\Controllers;

use App\Car;
use App\Helpers\VehicleHelper;
use App\Http\Requests\NewVisitRequest;
use App\Maintenance;
use App\MaintenanceType;
use App\Notifications\CustomerBookedVisit;
use App\Providers\App\Events\ChangeVehicleStatus;
use App\Providers\App\Events\InvoicePaid;
use App\Providers\App\Events\VisitBookByCustomer;
use App\User;
use App\Visit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;

class MaintenancesController extends Controller
{

    /**
     * @return View
     */
    // show "Book a visit" page
    public function add(): View
    {
        $cars = Car::where('owner_id', Auth::id())->get();
        $types = MaintenanceType::all();
        $events = Visit::whereDate('start', '>=', Carbon::today())->get();

        return view('bookVisit', compact('cars', 'types', 'events'));
    }

    /**
     * @param NewVisitRequest $request
     * @return RedirectResponse
     */
    // Add newly booked visit to the DB
    public function create(NewVisitRequest $request)
    {
        // check if the events are not overlapping, once again
        if(Visit::where('start', $request->input('start'))->first()) {
            Session::flash('message', __('global.error-time-unavailable'));
            Session::flash('alert-class', 'danger');

            return redirect()->route('upcomingVisits');
        }

        // add a visit to the DB
        $visit = new Visit($request->except('_token'));
        $visit->user_id = Auth::id();
        $visit->status_id = '1';
        $visit->save();

        event(new VisitBookByCustomer($request->car_id, 4));

        $this->sendNotificationMessage(Auth::id(), $visit, $visit->vehicle);

        Session::flash('message', __('global.done-visit-booked'));
        Session::flash('alert-class', 'success');

        return redirect()->route('upcomingVisits');
    }

    /**
     * @return View
     */
    // List all upcoming visits from today;
    public function upcomingVisits(): View
    {
        $visits = Visit::whereDate('start', '>=', Carbon::today())->where('user_id', Auth::id())->orderBy('start', 'asc')->get();
        return view('upcomingVists', compact('visits'));
    }

    /**
     * @param $id
     * @return View
     */
    // Show current booked visit's information
    public function showVisit($id): View
    {
        $visit = Visit::findOrFail($id);

        if($visit->user_id != Auth::id()) {
            abort(404);
        };

        return view('showVisit', compact('visit'));
    }

    /**
     * @param $id
     * @return RedirectResponse
     */
    // Cancels booked visit and removes it from DB
    public function cancelVisit($id) {
        $visit = Visit::findOrFail($id);

        if($visit) {
            if($visit->user_id != Auth::id()) {
                abort(404);
            };

            event(new VisitBookByCustomer($visit->car_id, 1));
            $visit->delete();


            // Check if current vehicle is not in open maintenance, then change status to "Ok", else change it to "In progress"
            if(Maintenance::where('car_id', $visit->car_id)->where('finish_date', null)->where('maintenance_status_id', 1)->exists()) {
                event(new VisitBookByCustomer($visit->car_id, 2));
            } else {
                event(new VisitBookByCustomer($visit->car_id, 1));
            }

            Session::flash('message', __('global.visit-booking-revoked'));
            Session::flash('alert-class', 'success');

            return redirect()->route('upcomingVisits');
        } else {
            Session::flash('message', __('global.visit-not-found'));
            Session::flash('alert-class', 'danger');

            return redirect()->route('upcomingVisits');
        }
    }

    /**
     * @param int $user_id
     * @param Visit $visit
     * @param $car
     * @return void
     */
    private function sendNotificationMessage(int $user_id, Visit $visit, $car): void
    {
        $details = [
            'date' => $visit->start,
            'car' => $car->licence_plate,
            'id' => $visit->id
        ];
        $user = User::findOrFail($user_id);
        $user->notify(new CustomerBookedVisit($details));
    }
}
