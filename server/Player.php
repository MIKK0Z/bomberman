<?php

class Player implements JsonSerializable {
    /** @var resource stram socket */
    private $client;

    private string $peerName;

    /**
     * Constructor for the Player class.
     * 
     * @param resource $client stream socket.
     */
    public function __construct($client) {
        $this->client = $client;
        $this->peerName = stream_socket_get_name($client, true);

        if ($this->peerName === false) {
            throw new Exception("Failed to get peer name");
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

    public function getPeerName(): string {
        return $this->peerName;
    }

    public function jsonSerialize(): mixed {
        return get_object_vars($this);
    }
}
