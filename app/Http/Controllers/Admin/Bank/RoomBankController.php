<?php

namespace App\Http\Controllers\Admin\Bank;

use App\Http\Controllers\Controller;
use App\Models\Bank\Transaction;
use App\Models\Claim;
use App\Models\Room;
use App\Models\UserRoom;
use App\Services\Policies\PolicyModel;
use Illuminate\Http\Request;

class RoomBankController extends Controller
{
  private $policy;

  public function __construct() {
    $this->policy = new PolicyModel();
  }

  public function show($id) {
    $this->policy->admin();
    $r = Room::findOrFail($id);
    return view('room.bank.show',compact('r'));
  }

  public function players($id) {
    $this->policy->admin();
    $r = Room::findOrFail($id);
    return view('room.bank.players',compact('r'));
  }

  public function playersBanker(Request $request, $id) {
    $this->policy->admin();
    $r = Room::findOrFail($id);
    $option = $request->input('option');
    $user_id = $request->input('user_id');

    if ($option == 'bank') {
      $ur = UserRoom::where('room_id', $id)->where('user_id', $user_id)->first();
      $ur->banker = !$ur->banker;
      $ur->update();
    }

    return back()->with('success', 'actualizado');
  }

  public function transactions($id) {
    $this->policy->admin();
    $r = Room::findOrFail($id);
    $transactions = Transaction::where('room_id', $id)->with(['transmitter_user','receiver_user'])->orderBy('id', 'desc')->get();

    return view('room.bank.transactions',compact('r', 'transactions'));
  }
}
