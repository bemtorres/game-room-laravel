<div class="col-12 col-lg-8 col-xxl-9 d-flex">
  <div class="card flex-fill">
    {{-- <div class="card-header"> --}}

      {{-- <h5 class="card-title mb-0">Latest Projects</h5> --}}
    {{-- </div> --}}
    <div class="card-body">
      <table class="table table-hover">
        <thead>
          <tr>
            <th>Fecha</th>
            <th>Descripción</th>
            <th>
                <img src="{{ asset('RoomGame.svg') }}" width="20" height="20" class="ms-2" />Monto
            </th>
          </tr>
        </thead>
        <tbody>
          @foreach ($u->cuentas as $c)
          <tr>
            <th class="align-middle">
              {{ $c->getFechaCreacion()->getDatetime() }}
            </th>
            <td class="align-middle">
              {{ $c->description }}
            </td>
            <td class="align-middle">
              <h5>
                <img src="{{ asset('RoomGame.svg') }}" width="20" height="20" class="" />
                <span class="badge bg-{{ $c->price > 0 ? 'success' : 'danger' }}">
                  {{ $c->getPrice() }}
                </span>
              </h5>
            </td>
          </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
</div>
