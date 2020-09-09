<?php
namespace DB\Validate;

class Resp
{
    private $messages = [];

    public function set(string $status, string $text, string $suggest = null, array $fix = null): void
    {
        $valid_statuses = ['success', 'info', 'warning', 'danger', 'head'];
        if (!in_array($status, $valid_statuses)) {
            throw new \Exception("Invalid status: $status. Only " .  implode(', ', $valid_statuses . " allowed"));
        }
        $res = [
            "status" => $status,
            "text" => $text,
        ];
        if ($suggest){
            $res['suggest'] = $suggest;
        }
        if ($fix && is_array($fix) && in_array($fix[0],  ['create', 'delete'])) {
            $res['fix'] = $fix;
        }
        
        array_push($this->messages,  $res);
    }

    public function get(): array
    {
        return $this->messages;
    }

}