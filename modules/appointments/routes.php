<?php
use App\Modules\Appointments\AppointmentController;

return [
    'appointments'                => ['GET', [AppointmentController::class, 'index'], 'appointments.view'],
    'appointments/book'           => ['POST', [AppointmentController::class, 'book'], 'appointments.book'],
    'appointments/update'         => ['POST', [AppointmentController::class, 'update'], 'appointments.book|frontdesk.checkin'],
    'appointments/schedules'      => ['GET', [AppointmentController::class, 'schedules'], 'appointments.schedule.own|appointments.slots.manage'],
    'appointments/schedule-save'  => ['POST', [AppointmentController::class, 'saveSchedule'], 'appointments.schedule.own|appointments.slots.manage'],
    'appointments/leaves'         => ['GET', [AppointmentController::class, 'leaves'], 'appointments.schedule.own|appointments.leave.approve'],
    'appointments/leave-request'  => ['POST', [AppointmentController::class, 'requestLeave'], 'appointments.schedule.own|appointments.leave.approve'],
    'appointments/leave-decide'   => ['POST', [AppointmentController::class, 'decideLeave'], 'appointments.leave.approve'],
];
