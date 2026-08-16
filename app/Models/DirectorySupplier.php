<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * SEL-048: Diretorio editorial de fornecedores da Lista de Fornecedores.
 *
 * IMPORTANTE: este model NAO usa TenantSupplierScope.
 * E conteudo editorial (catalogo publico/semi-publico) — nao dados operacionais.
 * A tabela directory_suppliers e separada de suppliers (fornecedores de dropshipping).
 *
 * Gate por plano: se min_plan_id nao-null, o controller oculta contatos
 * para usuarios cujo plano seja inferior. Hoje min_plan_id = null = liberado.
 *
 * @property int         $id
 * @property string      $name
 * @property string      $slug
 * @property array|null  $categories
 * @property string|null $description
 * @property string|null $location
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $whatsapp
 * @property string|null $instagram
 * @property array|null  $other_socials
 * @property string|null $site
 * @property string|null $catalog_url
 * @property array|null  $marketplaces
 * @property string|null $min_order
 * @property string|null $shipping_info
 * @property string|null $commercial_terms
 * @property string|null $logo_url
 * @property string|null $cover_url
 * @property array|null  $sources
 * @property string|null $notes
 * @property int|null    $min_plan_id
 * @property bool        $verified
 * @property bool        $is_active
 */
class DirectorySupplier extends Model
{
    use HasFactory;

    protected $table = 'directory_suppliers';

    protected $fillable = [
        'name',
        'slug',
        'categories',
        'description',
        'location',
        'email',
        'phone',
        'whatsapp',
        'instagram',
        'other_socials',
        'site',
        'catalog_url',
        'marketplaces',
        'min_order',
        'shipping_info',
        'commercial_terms',
        'logo_url',
        'cover_url',
        'sources',
        'notes',
        'min_plan_id',
        'verified',
        'is_active',
    ];

    protected $casts = [
        'categories'    => 'array',
        'other_socials' => 'array',
        'marketplaces'  => 'array',
        'sources'       => 'array',
        'verified'      => 'boolean',
        'is_active'     => 'boolean',
    ];

    // ------------------------------------------------------------------ Scopes

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('verified', true);
    }

    /** Filtra fornecedores que vendem em determinado marketplace. */
    public function scopeInMarketplace(Builder $query, string $marketplace): Builder
    {
        return $query->whereJsonContains('marketplaces', $marketplace);
    }

    /** Filtra fornecedores que tenham pelo menos uma categoria da lista. */
    public function scopeInCategory(Builder $query, string $category): Builder
    {
        return $query->whereJsonContains('categories', $category);
    }

    /**
     * Busca fulltext em name e description (MySQL FULLTEXT).
     * Usa BOOLEAN MODE com wildcard para busca parcial.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        // Sanitiza o termo para BOOLEAN MODE
        $safe = preg_replace('/[+\-><()\~*\"@]+/', ' ', $term);
        $safe = trim($safe);

        if (empty($safe)) {
            return $query;
        }

        // Fix bug FULLTEXT: espaço duplo gera termo vazio -> "+*" inválido.
        // preg_split com NO_EMPTY remove tokens vazios.
        $tokens = preg_split('/\s+/', $safe, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (empty($tokens)) {
            return $query;
        }
        $boolean = '+' . implode('* +', $tokens) . '*';
        $like    = '%' . $safe . '%';

        // SEL-066: busca cruzada — alem do fulltext em name/description,
        // cobre notes, marketplaces e categories (marca/produto citados nos textos).
        return $query->where(function (Builder $w) use ($boolean, $like) {
            $w->whereRaw('MATCH(name, description) AGAINST(? IN BOOLEAN MODE)', [$boolean])
              ->orWhere('name', 'like', $like)
              ->orWhere('description', 'like', $like)
              ->orWhere('notes', 'like', $like)
              ->orWhere('marketplaces', 'like', $like)
              ->orWhere('categories', 'like', $like);
        });
    }

    // --------------------------------------------------------------- Helpers

    /**
     * Verifica se o usuario tem plano suficiente para ver os contatos.
     * Se min_plan_id == null, retorna true (liberado para todos).
     *
     * @param int|null $userPlanId  ID do plano do usuario autenticado
     */
    public function isContactUnlocked(?int $userPlanId): bool
    {
        if ($this->min_plan_id === null) {
            return true;
        }

        if ($userPlanId === null) {
            return false;
        }

        // Planos com ID maior = plano superior (Start 85 < Scaling 86 < Pro 87)
        return $userPlanId >= $this->min_plan_id;
    }

    /**
     * Retorna dados do fornecedor prontos para a API.
     * Contatos ficam null se o usuario nao tiver plano suficiente.
     *
     * @param int|null $userPlanId  ID do plano do usuario autenticado (null = sem plano)
     * @param bool     $full        true = inclui campos extras (detalhe); false = lista
     */
    public function toApiArray(?int $userPlanId = null, bool $full = false): array
    {
        $unlocked = $this->isContactUnlocked($userPlanId);

        $data = [
            'id'           => $this->id,
            'name'         => $this->name,
            'slug'         => $this->slug,
            'categories'   => $this->categories,
            'description'  => $this->description,
            'location'     => $this->location,
            'verified'     => $this->verified,
            'logo_url'     => $this->logo_url,
            'cover_url'    => $this->cover_url,
            'marketplaces' => $this->marketplaces,
            'min_order'    => $this->min_order,
            'has_catalog'  => !empty($this->catalog_url),
            'locked'       => !$unlocked,
            'updated_at'   => $this->updated_at?->toIso8601String(),
        ];

        // Campos de contato: null se locked
        $data['email']        = $unlocked ? $this->email        : null;
        $data['phone']        = $unlocked ? $this->phone        : null;
        $data['whatsapp']     = $unlocked ? $this->whatsapp     : null;
        $data['instagram']    = $unlocked ? $this->instagram    : null;
        $data['other_socials'] = $unlocked ? $this->other_socials : null;
        $data['site']         = $unlocked ? $this->site         : null;
        $data['catalog_url']  = $unlocked ? $this->catalog_url  : null;

        if ($full) {
            $data['shipping_info']    = $this->shipping_info;
            $data['commercial_terms'] = $this->commercial_terms;
            $data['notes']            = $this->notes;
            $data['sources']          = $this->sources;
            $data['created_at']       = $this->created_at?->toIso8601String();
        }

        return $data;
    }
}
