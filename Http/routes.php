<?php

Route::group(['middleware' => ['web', 'auth'], 'prefix' => 'calendar-invite', 'namespace' => 'Modules\CalendarInviteModule\Http\Controllers'], function () {
    Route::post('/create-draft', 'CalendarInviteController@createDraft');
});
