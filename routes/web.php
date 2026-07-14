<?php

use App\Http\Controllers\ClientAssignmentController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ClientObservationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GiftController;
use App\Http\Controllers\LossController;
use App\Http\Controllers\OrderBulkController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderItemController;
use App\Http\Controllers\PendingApprovalController;
use App\Http\Controllers\PricingController;
use App\Http\Controllers\TeamTaskController;
use App\Http\Controllers\TeamTaskItemController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\YearController;
use App\Http\Middleware\EnsureUserHasRole;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    // Fase 7 (correccion 4), seccion 1: Pedidos pasa a ser el centro
    // operativo de la app. Un usuario YA autenticado que entra a "/" va
    // directo a /orders (que a su vez aplica su propio middleware de
    // auth/rol normalmente). Un visitante NO autenticado sigue viendo la
    // pagina de bienvenida de siempre (con login/registro): no tiene
    // sentido mandarlo a /orders solo para que rebote al login, y el
    // prompt pide explicitamente "cuando corresponda", no incondicional.
    if (auth()->check()) {
        return redirect('/orders');
    }

    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
})->name('welcome');

// Fase 5C: pantalla de espera para un usuario autenticado sin rol asignado.
// Deliberadamente FUERA del grupo con EnsureUserHasRole (ver mas abajo), para
// que no se genere un loop de redirecciones y el usuario pendiente siempre
// pueda verla.
Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/pending-approval', [PendingApprovalController::class, 'show'])
        ->name('pending.approval');
});

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
    EnsureUserHasRole::class,
])->group(function () {

    // Fase 7 (correccion 4), seccion 1: Pedidos reemplaza al Dashboard como
    // pantalla principal. La ruta /dashboard se mantiene DENTRO del mismo
    // grupo de middleware (auth/verified/rol) para no cambiar en nada el
    // comportamiento de autenticacion/permisos existente (un usuario sin
    // rol sigue yendo a /pending-approval igual que antes, por ejemplo),
    // pero ahora redirige a /orders en vez de renderizar el Dashboard.
    // DashboardController.php y Dashboard.vue NO se eliminan (se conservan
    // tal cual por si se reutilizan a futuro, ver prompt de esta correccion);
    // solo se dejan de USAR como pantalla principal.
    Route::get('/dashboard', fn () => redirect('/orders'))
        ->name('dashboard');

    // Usuarios y roles (Fase 5C, Parte 1)
    Route::get('/users', [UserController::class, 'index'])
        ->name('users.index');

    Route::put('/users/{user}/role', [UserController::class, 'updateRole'])
        ->name('users.update-role');

    Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate'])
        ->name('users.deactivate');

    Route::post('/users/{user}/reactivate', [UserController::class, 'reactivate'])
        ->name('users.reactivate');

    // Clientes
    Route::get('/clients', [ClientController::class, 'index'])
        ->name('clients.index');

    // Autocomplete de clientes para el picker de alta de pedido (Fase 4).
    // Declarada ANTES de cualquier ruta con {client} para evitar ambiguedad.
    Route::get('/clients/search', [ClientController::class, 'search'])
        ->name('clients.search');

    Route::post('/clients', [ClientController::class, 'store'])
        ->name('clients.store');

    Route::post('/clients/bulk-delete', [ClientController::class, 'bulkDestroy'])
        ->name('clients.bulk-delete');

    Route::get('/clients/{client}/history', [ClientController::class, 'history'])
        ->name('clients.history');

    Route::put('/clients/{client}', [ClientController::class, 'update'])
        ->name('clients.update');

    Route::put('/clients/{client}/historical-number', [ClientController::class, 'updateHistoricalNumber'])
        ->name('clients.historical-number.update');

    // Fase 7, secciones 1/2/6: wrappers "por cliente + edicion" de las
    // acciones de asignacion (fusion visual con Asignaciones, ver
    // ClientController). Delegan en el mismo ClientAssignmentService.
    Route::post('/clients/{client}/assignment/self-assign', [ClientController::class, 'selfAssignForYear'])
        ->name('clients.assignment.self-assign');

    Route::post('/clients/{client}/assignment/transfer', [ClientController::class, 'transferForYear'])
        ->name('clients.assignment.transfer');

    Route::put('/clients/{client}/assignment/contact', [ClientController::class, 'updateContactForYear'])
        ->name('clients.assignment.contact.update');

    Route::delete('/clients/{client}/assignment', [ClientController::class, 'removeFromYear'])
        ->name('clients.assignment.remove');

    Route::delete('/clients/{client}', [ClientController::class, 'destroy'])
        ->name('clients.destroy');

    Route::post('/clients/{client}/restore', [ClientController::class, 'restore'])
        ->withTrashed()
        ->name('clients.restore');

    // Observaciones del cliente
    Route::post('/clients/{client}/observations', [ClientObservationController::class, 'store'])
        ->name('clients.observations.store');

    // Fase 6A: asignaciones anuales de clientes / call center.
    Route::get('/assignments', [ClientAssignmentController::class, 'index'])
        ->name('assignments.index');

    Route::get('/assignments/export', [ClientAssignmentController::class, 'export'])
        ->name('assignments.export');

    Route::put('/assignments/{assignment}/contact', [ClientAssignmentController::class, 'updateContact'])
        ->name('assignments.contact.update');

    Route::post('/assignments/{assignment}/self-assign', [ClientAssignmentController::class, 'selfAssign'])
        ->name('assignments.self-assign');

    Route::post('/assignments/{assignment}/transfer', [ClientAssignmentController::class, 'transfer'])
        ->name('assignments.transfer');

    Route::post('/assignments/bulk-assign', [ClientAssignmentController::class, 'bulkAssign'])
        ->name('assignments.bulk-assign');

    Route::post('/assignments/bulk-distribute', [ClientAssignmentController::class, 'bulkDistribute'])
        ->name('assignments.bulk-distribute');

    Route::post('/assignments/generate-preview', [ClientAssignmentController::class, 'generatePreview'])
        ->name('assignments.generate-preview');

    Route::post('/assignments/generate', [ClientAssignmentController::class, 'generate'])
        ->name('assignments.generate');

    // Pedidos
    Route::get('/orders', [OrderController::class, 'index'])
        ->name('orders.index');

    Route::get('/orders/create', [OrderController::class, 'create'])
        ->name('orders.create');

    Route::post('/pricing/preview', [PricingController::class, 'preview'])
        ->name('pricing.preview');

    Route::post('/pricing/preview-portions', [PricingController::class, 'previewPortions'])
        ->name('pricing.preview-portions');

    Route::post('/orders', [OrderController::class, 'store'])
        ->name('orders.store');

    Route::get('/orders/{order}/edit', [OrderController::class, 'edit'])
        ->name('orders.edit');

    Route::put('/orders/{order}', [OrderController::class, 'update'])
        ->name('orders.update');

    Route::put('/orders/{order}/portions', [OrderController::class, 'updatePortions'])
        ->name('orders.portions.update');

    Route::delete('/orders/{order}', [OrderController::class, 'destroy'])
        ->name('orders.destroy');

    // Lineas de pedido (Fase 4)
    Route::post('/orders/{order}/items', [OrderItemController::class, 'store'])
        ->name('orders.items.store');

    Route::put('/orders/{order}/items/{item}', [OrderItemController::class, 'update'])
        ->name('orders.items.update');

    Route::delete('/orders/{order}/items/{item}', [OrderItemController::class, 'destroy'])
        ->name('orders.items.destroy');

    // Acciones masivas sobre pedidos (Fase 4). Las mismas rutas sirven para
    // accion individual mandando un array de un solo elemento, evitando
    // duplicar controllers para "individual" vs "masivo".
    Route::post('/orders/bulk-assign', [OrderBulkController::class, 'assignRover'])
        ->name('orders.bulk-assign');

    Route::post('/orders/bulk-pay', [OrderBulkController::class, 'pay'])
        ->name('orders.bulk-pay');

    Route::post('/orders/bulk-withdraw', [OrderBulkController::class, 'withdraw'])
        ->name('orders.bulk-withdraw');

    // Fase 7, seccion 9: contraparte del checkbox de retiro (desmarcar).
    Route::post('/orders/bulk-unwithdraw', [OrderBulkController::class, 'unwithdraw'])
        ->name('orders.bulk-unwithdraw');

    // Fase 7, seccion 10: accion masiva principal "Cobrar y retirar seleccionados".
    Route::post('/orders/bulk-pay-and-withdraw', [OrderBulkController::class, 'payAndWithdraw'])
        ->name('orders.bulk-pay-and-withdraw');

    // Fase 7, seccion 8: advertencia de pedido duplicado (funciona igual
    // desde Clientes, Pedidos o cualquier otro punto de acceso, ver seccion 8).
    Route::get('/orders/check-existing', [OrderController::class, 'checkExisting'])
        ->name('orders.check-existing');

    // Regalos / porciones regaladas (Fase 5B)
    Route::get('/gifts', [GiftController::class, 'index'])
        ->name('gifts.index');

    Route::post('/gifts', [GiftController::class, 'store'])
        ->name('gifts.store');

    Route::put('/gifts/{gift}', [GiftController::class, 'update'])
        ->name('gifts.update');

    Route::delete('/gifts/{gift}', [GiftController::class, 'destroy'])
        ->name('gifts.destroy');

    // Perdidas / porciones perdidas (Fase 5B)
    Route::get('/losses', [LossController::class, 'index'])
        ->name('losses.index');

    Route::post('/losses', [LossController::class, 'store'])
        ->name('losses.store');

    Route::put('/losses/{loss}', [LossController::class, 'update'])
        ->name('losses.update');

    Route::delete('/losses/{loss}', [LossController::class, 'destroy'])
        ->name('losses.destroy');

    // Años / selector de edición / parametros de precios (Fase 4.1)
    Route::get('/parameters', [YearController::class, 'parameters'])
        ->name('parameters.index');

    Route::put('/years/{year}', [YearController::class, 'update'])
        ->name('years.update');

    Route::post('/years', [YearController::class, 'store'])
        ->name('years.store');

    Route::post('/years/{year}/activate', [YearController::class, 'activate'])
        ->name('years.activate');

    // Fase 9: equipos operativos y checklists de tareas.
    Route::prefix('teams/{team}')
        ->where(['team' => 'logistica|compras|infraestructura|publicidad'])
        ->group(function () {
            Route::get('/', [TeamTaskController::class, 'index'])->name('teams.show');
            Route::post('/tasks', [TeamTaskController::class, 'store'])->name('teams.tasks.store');
            Route::put('/tasks/{task}', [TeamTaskController::class, 'update'])->name('teams.tasks.update');
            Route::post('/tasks/{task}/toggle', [TeamTaskController::class, 'toggle'])->name('teams.tasks.toggle');
            Route::delete('/tasks/{task}', [TeamTaskController::class, 'destroy'])->name('teams.tasks.destroy');
            // Subtareas (items) de cada tarea
            Route::post('/tasks/{task}/items', [TeamTaskItemController::class, 'store'])->name('teams.task-items.store');
            Route::put('/tasks/{task}/items/{item}', [TeamTaskItemController::class, 'update'])->name('teams.task-items.update');
            Route::post('/tasks/{task}/items/{item}/toggle', [TeamTaskItemController::class, 'toggle'])->name('teams.task-items.toggle');
            Route::delete('/tasks/{task}/items/{item}', [TeamTaskItemController::class, 'destroy'])->name('teams.task-items.destroy');
        });
});