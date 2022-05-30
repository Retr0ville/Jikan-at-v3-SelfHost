<?php

namespace App;

use App\Http\HttpHelper;
use Jenssegers\Mongodb\Eloquent\Model;
use Jikan\Helper\Media;
use Jikan\Helper\Parser;
use Jikan\Jikan;
use Jikan\Model\Common\YoutubeMeta;
use Jikan\Request\Anime\AnimeRequest;
use Laravel\Scout\Builder;
use Typesense\LaravelTypesense\Interfaces\TypesenseDocument;
use Laravel\Scout\Searchable;

class Anime extends Model implements TypesenseDocument
{
    use JikanSearchable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'mal_id','url','title','title_english','title_japanese','title_synonyms', 'images', 'type','source','episodes','status','airing','aired','duration','rating','score','scored_by','rank','popularity','members','favorites','synopsis','background','premiered','broadcast','related','producers','licensors','studios','genres', 'explicit_genres', 'themes', 'demographics', 'opening_themes','ending_themes'
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = ['season', 'year', 'themes'];

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'anime';

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [
        '_id', 'premiered', 'opening_themes', 'ending_themes', 'request_hash', 'expiresAt'
    ];

    public function setSeasonAttribute($value)
    {
        $this->attributes['season'] = $this->getSeasonAttribute();
    }

    public function getSeasonAttribute()
    {
        $premiered = $this->attributes['premiered'];

        if (empty($premiered)
            || is_null($premiered)
            || !preg_match('~(Winter|Spring|Summer|Fall|)\s([\d+]{4})~', $premiered)
        ) {
            return null;
        }

        $season = explode(' ', $premiered)[0];
        return strtolower($season);
    }

    public function setYearAttribute($value)
    {
        $this->attributes['year'] = $this->getYearAttribute();
    }

    public function getYearAttribute()
    {
        $premiered = $this->attributes['premiered'];

        if (empty($premiered)
            || is_null($premiered)
            || !preg_match('~(Winter|Spring|Summer|Fall|)\s([\d+]{4})~', $premiered)
        ) {
            return null;
        }

        return (int) explode(' ', $premiered)[1];
    }

    public function setBroadcastAttribute($value)
    {
        $this->attributes['year'] = $this->getBroadcastAttribute();
    }

    public function getBroadcastAttribute()
    {
        $broadcastStr = $this->attributes['broadcast'];

        if (!preg_match('~(.*) at (.*) \(~', $broadcastStr, $matches)) {
            return [
                'day' => null,
                'time' => null,
                'timezone' => null,
                'string' => $broadcastStr
            ];
        }

        if (preg_match('~(.*) at (.*) \(~', $broadcastStr, $matches)) {
            return [
                'day' => $matches[1],
                'time' => $matches[2],
                'timezone' => 'Asia/Tokyo',
                'string' => $broadcastStr
            ];
        }

        return [
            'day' => null,
            'time' => null,
            'timezone' => null,
            'string' => null
        ];
    }

    public static function scrape(int $id)
    {
        $data = app('JikanParser')->getAnime(new AnimeRequest($id));

        return HttpHelper::serializeEmptyObjectsControllerLevel(
            json_decode(
                app('SerializerV4')
                    ->serialize($data, 'json'),
                true
            )
        );
    }

    /**
     * Get the name of the index associated with the model.
     *
     * @return string
     */
    public function searchableAs(): string
    {
        return 'anime_index';
    }

    /**
     * Get the value used to index the model.
     *
     * @return mixed
     */
    public function getScoutKey(): mixed
    {
        return $this->mal_id;
    }

    /**
     * Get the key name used to index the model.
     *
     * @return mixed
     */
    public function getScoutKeyName(): mixed
    {
        return 'mal_id';
    }

    /**
     * Get the indexable data array for the model.
     *
     * @return array
     */
    public function toSearchableArray(): array
    {
        $serializer = app('SerializerV4');
        $result =  [
            'id' => (string) $this->mal_id,
            'mal_id' => (string) $this->mal_id,
            'start_date' => $this->aired['from'] ? Parser::parseDate($this->aired['from'])->getTimestamp() : 0,
            'end_date' => $this->aired['to'] ? Parser::parseDate($this->aired['to'])->getTimestamp() : 0,
            'url' => $this->url,
            'images' => $this->images,
            'trailer' => $this->trailer,
            'title' => $this->title,
            'title_english' => $this->title_english,
            'title_japanese' => $this->title_japanese,
            'title_synonyms' => $this->title_synonyms,
            'type' => $this->type,
            'source' => $this->source,
            'episodes' => $this->episodes,
            'status' => $this->status,
            'airing' => $this->airing,
            'duration' => $this->duration,
            'rating' => $this->rating,
            'score' => $this->score,
            'scored_by' => $this->scored_by,
            'rank' => $this->rank,
            'popularity' => $this->popularity,
            'members' => $this->members,
            'favorites' => $this->favorites,
            'synopsis' => $this->synopsis,
            'background' => $this->background,
            'season' => $this->season,
            'year' => $this->year,
            'broadcast' => $this->broadcast,
            'producers' => $serializer->serialize($this->producers, 'json'),
            'licensors' => $serializer->serialize($this->licensors, 'json'),
            'studios' => $serializer->serialize($this->studios, 'json'),
            'genres' => $serializer->serialize($this->genres, 'json'),
            'explicit_genres' => $serializer->serialize($this->explicit_genres, 'json'),
            'themes' => $serializer->serialize($this->themes, 'json'),
            'demographics' => $serializer->serialize($this->demographics, 'json'),
        ];

        return $result;
    }

    /**
     * The fields to be queried against. See https://typesense.org/docs/0.21.0/api/documents.html#search.
     *
     * @return array
     */
    public function typesenseQueryBy(): array
    {
        return [
            'title',
            'title_english',
            'title_japanese',
            'title_synonyms'
        ];
    }

    /**
     * The Typesense schema to be created.
     *
     * @return array
     */
    public function getCollectionSchema(): array
    {
        return [
            'name' => $this->searchableAs(),
            'fields' => [
                [
                    'name' => '.*',
                    'type' => 'auto',
                ]
            ]
        ];
    }
}