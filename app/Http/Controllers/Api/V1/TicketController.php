<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TicketController extends Controller
{
    private function isAdmin(Request $request): bool
    {
        return in_array($request->user()->role, ['super_admin', 'admin']);
    }

    private function clientOrFail(Request $request)
    {
        $client = $request->user()->client;
        if (!$client) abort(403, "Usuario nao possui perfil de lojista.");
        return $client;
    }

    private function supplierOrFail(Request $request)
    {
        $supplier = \App\Models\Supplier::where("user_id", $request->user()->id)->first();
        if (!$supplier) abort(403, "Usuario nao possui perfil de fornecedor.");
        return $supplier;
    }

    public function index(Request $request): JsonResponse
    {
        if ($this->isAdmin($request)) {
            $query = SupportTicket::with(["client" => fn($q) => $q->select("id","user_id")->with("user:id,name,full_name")])
                ->withCount("messages")->latest();
            if ($request->filled("status") && $request->query("status") !== "all") $query->where("status", $request->query("status"));
            if ($request->filled("priority") && $request->query("priority") !== "all") $query->where("priority", $request->query("priority"));
            if ($request->filled("search")) {
                $s = "%" . $request->query("search") . "%";
                $query->where(function ($q) use ($s) { $q->where("title","like",$s)->orWhere("description","like",$s); });
            }
            return response()->json(["data" => $query->limit(500)->get()]);
        }
        if ($request->user()->role === "supplier") {
            return $this->supplierIndex($request);
        }
        $client = $this->clientOrFail($request);
        $query = SupportTicket::where("client_id", $client->id)->latest();
        if ($request->filled("status") && $request->query("status") !== "all") $query->where("status", $request->query("status"));
        if ($request->filled("priority") && $request->query("priority") !== "all") $query->where("priority", $request->query("priority"));
        if ($request->filled("search")) {
            $s = "%" . $request->query("search") . "%";
            $query->where(function ($q) use ($s) { $q->where("title","like",$s)->orWhere("description","like",$s); });
        }
        return response()->json(["data" => $query->withCount("messages")->limit(200)->get()]);
    }

    private function supplierIndex(Request $request): JsonResponse
    {
        $supplier = $this->supplierOrFail($request);
        $clientIds = \App\Models\Order::where("supplier_id", $supplier->id)->distinct()->pluck("client_id");
        $query = SupportTicket::whereIn("client_id", $clientIds)
            ->with(["client" => fn($q) => $q->select("id","user_id")->with("user:id,name,full_name")])
            ->withCount("messages")->latest();
        if ($request->filled("status") && $request->query("status") !== "all") $query->where("status", $request->query("status"));
        if ($request->filled("priority") && $request->query("priority") !== "all") $query->where("priority", $request->query("priority"));
        return response()->json(["data" => $query->limit(200)->get()]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if ($this->isAdmin($request)) {
            $ticket = SupportTicket::findOrFail($id);
        } elseif ($request->user()->role === "supplier") {
            $sup = $this->supplierOrFail($request);
            $cids = \App\Models\Order::where("supplier_id",$sup->id)->distinct()->pluck("client_id");
            $ticket = SupportTicket::whereIn("client_id",$cids)->where("id",$id)->firstOrFail();
        } else {
            $client = $this->clientOrFail($request);
            $ticket = SupportTicket::where("id",$id)->where("client_id",$client->id)->firstOrFail();
        }
        $ticket->load(["messages" => fn($q) => $q->orderBy("created_at","asc")]);
        return response()->json(["data" => $ticket]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($this->isAdmin($request)) {
            $data = $request->validate(["client_id"=>"required|integer","title"=>"required|string|max:200","category"=>"nullable|string|in:payment,order,product,delivery,integration,other","priority"=>"nullable|string|in:low,medium,high,urgent","description"=>"nullable|string|max:5000","related_order_id"=>"nullable|integer"]);
            $ticket = SupportTicket::create(["client_id"=>$data["client_id"],"title"=>$data["title"],"category"=>$data["category"]??"other","priority"=>$data["priority"]??"medium","status"=>"new","description"=>$data["description"]??null,"related_order_id"=>$data["related_order_id"]??null]);
            if (!empty($data["description"])) SupportTicketMessage::create(["ticket_id"=>$ticket->id,"author_type"=>"admin","author_user_id"=>$user->id,"body"=>$data["description"]]);
            return response()->json(["data" => $ticket->fresh("messages")], 201);
        }
        if ($user->role === "supplier") {
            $sup = $this->supplierOrFail($request);
            $data = $request->validate(["client_id"=>"required|integer","title"=>"required|string|max:200","category"=>"nullable|string|in:payment,order,product,delivery,integration,other","priority"=>"nullable|string|in:low,medium,high,urgent","description"=>"nullable|string|max:5000","related_order_id"=>"nullable|integer"]);
            $cids = \App\Models\Order::where("supplier_id",$sup->id)->distinct()->pluck("client_id");
            if (!$cids->contains((int)$data["client_id"])) abort(403, "Cliente nao pertence a este fornecedor.");
            $ticket = SupportTicket::create(["client_id"=>$data["client_id"],"title"=>$data["title"],"category"=>$data["category"]??"other","priority"=>$data["priority"]??"medium","status"=>"new","description"=>$data["description"]??null,"related_order_id"=>$data["related_order_id"]??null]);
            if (!empty($data["description"])) SupportTicketMessage::create(["ticket_id"=>$ticket->id,"author_type"=>"admin","author_user_id"=>$user->id,"body"=>$data["description"]]);
            return response()->json(["data" => $ticket->fresh("messages")], 201);
        }
        $client = $this->clientOrFail($request);
        $data = $request->validate(["title"=>"required|string|max:200","category"=>"nullable|string|in:payment,order,product,delivery,integration,other","priority"=>"nullable|string|in:low,medium,high,urgent","description"=>"nullable|string|max:5000","related_order_id"=>"nullable|integer"]);
        $ticket = SupportTicket::create(["client_id"=>$client->id,"title"=>$data["title"],"category"=>$data["category"]??"other","priority"=>$data["priority"]??"medium","status"=>"new","description"=>$data["description"]??null,"related_order_id"=>$data["related_order_id"]??null]);
        if (!empty($data["description"])) SupportTicketMessage::create(["ticket_id"=>$ticket->id,"author_type"=>"client","author_user_id"=>$request->user()->id,"body"=>$data["description"]]);
        return response()->json(["data" => $ticket->fresh("messages")], 201);
    }

    public function storeMessage(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if ($this->isAdmin($request)) {
            $ticket = SupportTicket::findOrFail($id);
            $authorType = "admin";
        } elseif ($user->role === "supplier") {
            $sup = $this->supplierOrFail($request);
            $cids = \App\Models\Order::where("supplier_id",$sup->id)->distinct()->pluck("client_id");
            $ticket = SupportTicket::whereIn("client_id",$cids)->where("id",$id)->firstOrFail();
            $authorType = "supplier";
        } else {
            $client = $this->clientOrFail($request);
            $ticket = SupportTicket::where("id",$id)->where("client_id",$client->id)->firstOrFail();
            $authorType = "client";
        }
        $data = $request->validate(["body" => "required|string|max:5000"]);
        $msg = SupportTicketMessage::create(["ticket_id"=>$ticket->id,"author_type"=>$authorType,"author_user_id"=>$user->id,"body"=>$data["body"]]);
        if (in_array($ticket->status,["resolved","closed"])) $ticket->update(["status"=>"in_progress","resolved_at"=>null,"closed_at"=>null]);
        return response()->json(["data" => $msg], 201);
    }

    /**
     * MUL-142-F — Upload de imagem para um chamado ou mensagem.
     * POST /api/v1/tickets/{id}/upload
     * Campo: image (file, max 5MB, jpeg/png/gif/webp)
     * Retorna: { url: "https://..." }
     */
    public function uploadImage(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        // Verificar acesso ao ticket
        if ($this->isAdmin($request)) {
            $ticket = SupportTicket::findOrFail($id);
        } elseif ($user->role === "supplier") {
            $sup = $this->supplierOrFail($request);
            $cids = \App\Models\Order::where("supplier_id",$sup->id)->distinct()->pluck("client_id");
            $ticket = SupportTicket::whereIn("client_id",$cids)->where("id",$id)->firstOrFail();
        } else {
            $client = $this->clientOrFail($request);
            $ticket = SupportTicket::where("id",$id)->where("client_id",$client->id)->firstOrFail();
        }

        $request->validate([
            'image' => 'required|file|image|mimes:jpeg,png,gif,webp|max:5120',
        ]);

        $file = $request->file('image');
        $ext  = $file->getClientOriginalExtension() ?: 'jpg';
        $name = 'ticket_' . $id . '_' . time() . '_' . uniqid() . '.' . $ext;
        $path = $file->storeAs('public/tickets', $name);

        $url = url('storage/tickets/' . $name);

        return response()->json(['data' => ['url' => $url]], 201);
    }

    /**
     * MUL-142-F — Avaliar atendimento do chamado (seller only).
     * POST /api/v1/tickets/{id}/rate
     * Body: { rating: 1-5, comment?: string }
     * Só disponível quando status é "resolved" ou "closed".
     * Pode ser feito apenas uma vez por chamado (idempotente — atualiza se já existir).
     */
    public function rate(Request $request, int $id): JsonResponse
    {
        $client = $this->clientOrFail($request);
        $ticket = SupportTicket::where("id",$id)->where("client_id",$client->id)->firstOrFail();

        if (!in_array($ticket->status, ['resolved', 'closed'])) {
            return response()->json(['message' => 'Somente chamados resolvidos ou fechados podem ser avaliados.'], 422);
        }

        $data = $request->validate([
            'rating'  => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:500',
        ]);

        $ticket->update([
            'rating'         => $data['rating'],
            'rating_comment' => $data['comment'] ?? null,
            'rated_at'       => $ticket->rated_at ?? now(),
        ]);

        return response()->json(['data' => $ticket->fresh()]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if ($this->isAdmin($request)) {
            $ticket = SupportTicket::findOrFail($id);
        } elseif ($user->role === "supplier") {
            $sup = $this->supplierOrFail($request);
            $cids = \App\Models\Order::where("supplier_id",$sup->id)->distinct()->pluck("client_id");
            $ticket = SupportTicket::whereIn("client_id",$cids)->where("id",$id)->firstOrFail();
        } else {
            $client = $this->clientOrFail($request);
            $ticket = SupportTicket::where("id",$id)->where("client_id",$client->id)->firstOrFail();
        }
        $data = $request->validate(["status"=>"nullable|string|in:new,in_progress,resolved,closed","priority"=>"nullable|string|in:low,medium,high,urgent"]);
        $updates = [];
        if (!empty($data["status"])) { $updates["status"]=$data["status"]; if ($data["status"]==="resolved") $updates["resolved_at"]=now(); if ($data["status"]==="closed") $updates["closed_at"]=now(); }
        if (!empty($data["priority"])) $updates["priority"]=$data["priority"];
        if (!empty($updates)) $ticket->update($updates);
        return response()->json(["data" => $ticket->fresh()]);
    }
}
