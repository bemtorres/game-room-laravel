<?php

namespace App\Http\Controllers\Admin\Bank;

use Jenssegers\Agent\Agent;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Bank\PaymentRequest;
use App\Models\Bank\Transaction;
use App\Models\Room;
use App\Models\User;
use App\Models\UserRoom;
use App\Services\Policies\PolicyModel;
use Illuminate\Http\Request;


class GameController extends Controller
{
  public function show($room_id) {
    try {
      $r = Room::where('active',true)->where('status','<>',4)->findOrFail($room_id);
      $user_room = UserRoom::where('room_id',$r->id)->where('user_id',current_user()->id)->first();

      if ($user_room) {
        return redirect()->route('game.bank.play', $r->id);
      }

      return view('bank.game.index',compact('r'));
    } catch (\Throwable $th) {
      return redirect()->route('home')->with('info', 'Juego no disponible');
    }
  }

  public function enrollment(Request $request, $room_id) {
    try {
      $r = Room::where('active',true)->where('status',1)->findOrFail($room_id);
      $user_room = UserRoom::where('room_id',$r->id)->where('user_id',current_user()->id)->first();

      if (current_user()->credit < $r->price) {
        return back()->with('info','Ingresos insuficientes');
      }

      if (empty($user_room)) {
        $pass = $request->input('n1') . $request->input('n2') . $request->input('n3') . $request->input('n4');
        $nickname = $request->input('nickname');
        $img = $request->input('imagen');

        $config = [
          'pass' => $pass,
          'nickname' => $nickname,
          'img' => $img,
        ];

        $ur = new UserRoom();
        $ur->room_id = $room_id;
        $ur->user_id = current_user()->id;
        $ur->money   = 0;
        $ur->banker  = false;
        $ur->config  = $config;
        $ur->save();

        $r->cont_users++;
        $r->update();

        $u = User::find(current_user()->id);
        $u->credit -= $r->price;
        $u->update();

        $a = new Account();
        $a->user_id = current_user()->id;
        $a->room_id = $r->id;
        $a->description = 'INGRESO JUEGO BANCO | SALA ' . $r->id;
        $a->price = $r->price * -1;
        $a->save();
      }

      return redirect()->route('game.bank.play', $r->id);
    } catch (\Throwable $th) {
      // return $th;
      return redirect()->route('home')->with('info', 'Juego no disponible');
    }
  }

  public function play($room_id) {
    $r = Room::where('active',true)->where('status','<>',4)->findOrFail($room_id);
    $user_room = UserRoom::where('room_id',$room_id)
                                ->where('user_id',current_user()->id)
                                ->with(['room'])
                                ->firstOrFail();
    $contacts = UserRoom::where('room_id',$room_id)->where('user_id', '!=', current_user()->id)->get();

    $id = $user_room->id;
    $transactions = Transaction::where('room_id', $room_id)
                                ->where(function ($query) use ($id){
                                  return $query->orWhere('receiver_user_id', $id)
                                                ->orWhere('transmitter_user_id', $id);
                                })
                                ->with(['transmitter_user','receiver_user'])
                                ->orderBy('id', 'desc')
                                ->get();

    $payment_requests = PaymentRequest::where('room_id', $room_id)
                                      ->where('receiver_user_id', $id)
                                      ->where('status',1)
                                      ->with(['transmitter_user','receiver_user'])
                                      ->orderBy('id', 'desc')
                                      ->first();

    $isBanker = false;
    $agent = new Agent();
    $isMobile = $agent->isMobile();

    return view('bank.game.play',compact('r','user_room', 'contacts', 'isBanker', 'isMobile', 'transactions', 'payment_requests'));
  }

  public function playBanker($room_id) {
    try {
      $r = Room::where('active',true)->where('status','<>',4)->findOrFail($room_id);
      $user_room = UserRoom::where('room_id',$room_id)
                                  ->where('user_id',current_user()->id)
                                  ->where('banker',true)
                                  ->with(['room'])
                                  ->firstOrFail();

      $contacts = UserRoom::where('room_id',$room_id)->get();

      $isBanker = true;
      $agent = new Agent();
      $isMobile = $agent->isMobile();

      $id = 0;
      $transactions = Transaction::where('room_id', $room_id)
                                  ->where(function ($query) use ($id){
                                    return $query->orWhere('receiver_user_id', $id)
                                                  ->orWhere('transmitter_user_id', $id);
                                  })
                                  ->with(['transmitter_user','receiver_user'])
                                  ->orderBy('id', 'desc')
                                  ->get();


    $payment_requests = PaymentRequest::where('room_id', $room_id)
                                ->where('receiver_user_id', $id)
                                ->where('status',1)
                                ->with(['transmitter_user','receiver_user'])
                                ->orderBy('id', 'desc')
                                ->first();


      return view('bank.game.play',compact('r','user_room', 'contacts', 'isBanker', 'isMobile', 'transactions', 'payment_requests'));
    } catch (\Throwable $th) {
      return back()->with('error', 'Error intente nuevamente');
    }
  }

  public function transfer(Request $request, $room_id) {
    $status_code = 'error';
    $status_resp = 'Error intente nuevamente';

    $r = Room::where('active',true)->where('status','<>',4)->findOrFail($room_id);
    $user_room = UserRoom::where('room_id',$room_id)
                                ->where('user_id',current_user()->id)
                                ->firstOrFail();

    $contact_id = $request->input('contact_id');
    $pass = $request->input('n1') . $request->input('n2') . $request->input('n3') . $request->input('n4');
    $money = $request->input('money');
    $comment = $request->input('comment') ?? 'Transferencia electr??nica';

    $type = $request->input('type'); //BANK : USER

    if ($type == 'USER') {
      if ($pass == $user_room->getPassword()) {
        if ($user_room->money > 0 && $user_room->money >= $money) {
          // a quien deposita?
          $tr = new Transaction();
          $tr->room_id = $room_id;
          $tr->user_room_id = $user_room->id;
          $tr->money_bank = false;
          $tr->transmitter_user_id = $user_room->id; //usuario que se inscribio | 0 banco
          $tr->receiver_user_id = $contact_id;       //usuario que se inscribio | 0 banco
          $tr->config = [
            'comment' => $comment
          ];
          $tr->money = $money;
          $tr->save();

          $user_room->money -= $money;
          $user_room->update();
          if ($contact_id == 0) { // Banco
            $r->banker_money += $money;
            $r->update();
          } else { // contact
            $user_room_contact = UserRoom::where('room_id',$room_id)->find($contact_id);
            $user_room_contact->money += $money;
            $user_room_contact->update();
          }
          $status_code = 'success';
          $status_resp = 'Enviado';
        } else {
          $status_resp = 'Error, No tienes fondos disponibles';
        }
      } else {
        $status_resp = 'Error, GR PASS incorrecta';
      }
    } else{
      // Como banco
      if ($r->banker_money > 0 && $r->banker_money >= $money) {
          // a quien deposita?
          $tr = new Transaction();
          $tr->room_id = $room_id;
          $tr->user_room_id = $user_room->id;
          $tr->money_bank = true;
          $tr->transmitter_user_id = 0; // soy el banco
          $tr->receiver_user_id = $contact_id;
          $tr->config = [
            'comment' => $comment
          ];
          $tr->money = $money;
          $tr->save();

          $r->banker_money -= $money;
          $r->update();

          // contact
          $user_room_contact = UserRoom::where('room_id',$room_id)->find($contact_id);
          $user_room_contact->money += $money;
          $user_room_contact->update();

          $status_code = 'success';
          $status_resp = 'Enviado';
      } else {
        $status_resp = 'Error, No tienes fondos disponibles';
      }
    }

    return back()->with($status_code, $status_resp);
  }

  public function profile(Request $request, $room_id) {
    $pass = $request->input('n1') . $request->input('n2') . $request->input('n3') . $request->input('n4');
    $nickname = $request->input('nickname');

    $user_room = UserRoom::where('room_id',$room_id)
                            ->where('user_id',current_user()->id)
                            ->firstOrFail();

    $config = $user_room->config;
    $config['pass'] = $pass;
    $config['nickname'] = $nickname;
    $config['color'] = $request->input('color');
    $user_room->config = $config;
    $user_room->update();

    return back()->with('success', 'actualizado');
  }

  public function avatar(Request $request, $room_id) {
    $user_room = UserRoom::where('room_id',$room_id)
                            ->where('user_id',current_user()->id)
                            ->firstOrFail();

    $config = $user_room->config;
    $config['img'] = $request->input('imagen');
    $user_room->config = $config;
    $user_room->update();

    return back()->with('success', 'actualizado');
  }

  // cobrar
  public function charge(Request $request, $room_id) {
    $status_code = 'error';
    $status_resp = 'Error intente nuevamente';

    $r = Room::where('active',true)->where('status','<>',4)->findOrFail($room_id);
    $user_room = UserRoom::where('room_id',$room_id)
                                ->where('user_id',current_user()->id)
                                ->firstOrFail();

    $contact_id = $request->input('contact_id');
    $pass = $request->input('n1') . $request->input('n2') . $request->input('n3') . $request->input('n4');
    $money = $request->input('money');
    $comment = $request->input('comment') ?? 'Solcitiud de pago electr??nica';

    $type = $request->input('type'); //BANK : USER

    if ($type == 'USER') {
      if ($pass == $user_room->getPassword()) {
        // if ($user_room->money > 0 && $user_room->money >= $money) {
          // a quien deposita?
          $pr = new PaymentRequest();
          $pr->room_id = $room_id;
          $pr->user_room_id = $user_room->id;
          $pr->money_bank = false;
          $pr->transmitter_user_id = $user_room->id; //usuario que se inscribio | 0 banco
          $pr->receiver_user_id = $contact_id;       //usuario que se inscribio | 0 banco
          $pr->config = [
            'comment' => $comment
          ];
          $pr->money = $money;
          $pr->save();

          // $user_room->money -= $money;
          // $user_room->update();
          // if ($contact_id == 0) { // Banco
          //   $r->banker_money += $money;
          //   $r->update();
          // } else { // contact
          //   $user_room_contact = UserRoom::where('room_id',$room_id)->find($contact_id);
          //   $user_room_contact->money += $money;
          //   $user_room_contact->update();
          // }
          $status_code = 'success';
          $status_resp = 'Enviado';
        // } else {
        //   $status_resp = 'Error, No tienes fondos disponibles';
        // }
      } else {
        $status_resp = 'Error, GR PASS incorrecta';
      }
    } else{
      // Como banco
      // if ($r->banker_money > 0 && $r->banker_money >= $money) {
          // a quien deposita?
          $pr = new PaymentRequest();
          $pr->room_id = $room_id;
          $pr->user_room_id = $user_room->id;
          $pr->money_bank = true;
          $pr->transmitter_user_id = 0; // soy el banco
          $pr->receiver_user_id = $contact_id;
          $pr->config = [
            'comment' => $comment
          ];
          $pr->money = $money;
          $pr->save();

          // $r->banker_money -= $money;
          // $r->update();

          // // contact
          // $user_room_contact = UserRoom::where('room_id',$room_id)->find($contact_id);
          // $user_room_contact->money += $money;
          // $user_room_contact->update();

          $status_code = 'success';
          $status_resp = 'Enviado';
      // } else {
      //   $status_resp = 'Error, No tienes fondos disponibles';
      // }
    }

    return back()->with($status_code, $status_resp);
  }

  public function charge_cancel(Request $request, $room_id)
  {
    return $request;
  }

  // pagar
  public function payment(Request $request, $room_id) {
    return $request;
    // $status_code = 'error';
    // $status_resp = 'Error intente nuevamente';

    // $r = Room::where('active',true)->where('status','<>',4)->findOrFail($room_id);
    // $user_room = UserRoom::where('room_id',$room_id)
    //                             ->where('user_id',current_user()->id)
    //                             ->firstOrFail();

    // $contacts = UserRoom::where('room_id',$room_id)->get();

    // $contact_id = $request->input('contact_id');
    // $pass = $request->input('n1') . $request->input('n2') . $request->input('n3') . $request->input('n4');
    // $money = $request->input('money');
    // $comment = $request->input('comment') ?? 'Transferencia electr??nica';

    // $type = $request->input('type'); //BANK : USER

    // if ($type == 'USER') {
    //   if ($pass == $user_room->getPassword()) {
    //     if ($user_room->money > 0 && $user_room->money >= $money) {
    //       // a quien deposita?
    //       $tr = new Transaction();
    //       $tr->room_id = $room_id;
    //       $tr->user_room_id = $user_room->id;
    //       $tr->money_bank = false;
    //       $tr->transmitter_user_id = $user_room->id; //usuario que se inscribio | 0 banco
    //       $tr->receiver_user_id = $contact_id;       //usuario que se inscribio | 0 banco
    //       $tr->config = [
    //         'comment' => $comment
    //       ];
    //       $tr->money = $money;
    //       $tr->save();

    //       $user_room->money -= $money;
    //       $user_room->update();
    //       if ($contact_id == 0) { // Banco
    //         $r->banker_money += $money;
    //         $r->update();
    //       } else { // contact
    //         $user_room_contact = UserRoom::where('room_id',$room_id)->find($contact_id);
    //         $user_room_contact->money += $money;
    //         $user_room_contact->update();
    //       }
    //       $status_code = 'success';
    //       $status_resp = 'Enviado';
    //     } else {
    //       $status_resp = 'Error, No tienes fondos disponibles';
    //     }
    //   } else {
    //     $status_resp = 'Error, GR PASS incorrecta';
    //   }
    // } else{
    //   // Como banco
    //   if ($r->banker_money > 0 && $r->banker_money >= $money) {
    //       // a quien deposita?
    //       $tr = new Transaction();
    //       $tr->room_id = $room_id;
    //       $tr->user_room_id = $user_room->id;
    //       $tr->money_bank = true;
    //       $tr->transmitter_user_id = 0; // soy el banco
    //       $tr->receiver_user_id = $contact_id;
    //       $tr->config = [
    //         'comment' => $comment
    //       ];
    //       $tr->money = $money;
    //       $tr->save();

    //       $r->banker_money -= $money;
    //       $r->update();

    //       // contact
    //       $user_room_contact = UserRoom::where('room_id',$room_id)->find($contact_id);
    //       $user_room_contact->money += $money;
    //       $user_room_contact->update();

    //       $status_code = 'success';
    //       $status_resp = 'Enviado';
    //   } else {
    //     $status_resp = 'Error, No tienes fondos disponibles';
    //   }
    // }

    // return back()->with($status_code, $status_resp);
  }

}
