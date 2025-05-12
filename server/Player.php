<?php

declare(strict_types=1);

class Player implements JsonSerializable {
    /** @var resource stram socket */
    private $client;

    private readonly string $peerName;

    private float $pos_x;
    private float $pos_y;

    private int $vel_x;
    private int $vel_y;

    private string $fragmented_message = "";
    private int $fragmented_opcode = 0;
    private string $socket_buffer = "";

    /**
     * Constructor for the Player class.
     * 
     * @param resource $client stream socket.
     */
    public function __construct($client) {
        $this->client = $client;
        $this->peerName = stream_socket_get_name($client, true);

        $this->pos_x = 1;
        $this->pos_y = 1; // it should be multiplied by TILE_SIZE on client side

        $this->vel_x = 0;
        $this->vel_y = 0;
    }

    public function __destruct() {
        if (is_resource($this->client)) {
            fclose($this->client);
            unset($this->client);
        }
    }

    /**
     * Get the client stream socket.
     * 
     * @return resource The client stream socket.
     */
    public function getClient() {
        return $this->client;
    }

    /**
     * Get the client sockets of all players except this one.
     * 
     * @param array<Player> $players An array of Player objects.
     * @return array<resource> An array of client sockets that are not this player.
     */
    public function getOtherClients(array $players): array {
        $other_clients = array();
        foreach ($players as $player) {
            if ($player->getPeerName() !== $this->peerName) {
                array_push($other_clients, $player->getClient());
            }
        }
        return $other_clients;
    }

    public function getPeerName(): string {
        return $this->peerName;
    }

    public function getPosX(): float {
        return $this->pos_x;
    }

    public function setPosX(float $pos_x): void {
        $this->pos_x = $pos_x;
    }

    public function getPosY(): float {
        return $this->pos_y;
    }

    public function setPosY(float $pos_y): void {
        $this->pos_y = $pos_y;
    }

    public function getVelX(): int {
        return $this->vel_x;
    }

    public function setVelX(int $vel_x): void {
        $this->vel_x = $vel_x;
    }

    public function getVelY(): int {
        return $this->vel_y;
    }

    public function setVelY(int $vel_y): void {
        $this->vel_y = $vel_y;
    }

    public function getFragmentedMessage(): string {
        return $this->fragmented_message;
    }

    public function setFragmentedMessage(string $fragmented_message): void {
        $this->fragmented_message = $fragmented_message;
    }

    public function getFragmentedOpcode(): int {
        return $this->fragmented_opcode;
    }

    public function setFragmentedOpcode(int $fragmented_opcode): void {
        $this->fragmented_opcode = $fragmented_opcode;
    }

    public function getSocketBuffer(): string {
        return $this->socket_buffer;
    }

    public function appendToSocketBuffer(string $data): void {
        $this->socket_buffer .= $data;
    }

    public function consumeFromSocketBuffer(int $length): void {
        if ($length > 0) {
            $this->socket_buffer = substr($this->socket_buffer, $length);
        }
    }

    public function clearSocketBuffer(): void {
        $this->socket_buffer = "";
    }

    public function jsonSerialize(): mixed {
        return array(
            "pos_x" => $this->pos_x,
            "pos_y" => $this->pos_y,
            "vel_x" => $this->vel_x,
            "vel_y" => $this->vel_y,
        );
    }
}
