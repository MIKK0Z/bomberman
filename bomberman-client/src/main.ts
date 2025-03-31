import "./style.css"

const WS_HOST = "localhost";
const WS_PORT = 46089;
const WS_URL = `ws://${WS_HOST}:${WS_PORT}`;

const ws = new WebSocket(WS_URL);

const send = (data: any) => {
    ws.send(JSON.stringify(data));
}

const init = () => {
    ws.onopen = () => {
        console.log("connected");
    }

    ws.onmessage = (event) => {
	const jsonData = event.data;
	if (jsonData != "") {
	    try {
		const data = JSON.parse(jsonData);
		console.log(data);
	    } catch (error) {
		console.log(error);
	    }
	}
    }

    ws.onclose = () => {
        console.log("disconnected");
    }

    ws.onerror = (error) => {
        console.error(error);
    }
}

init();

const testbtn = document.querySelector<HTMLButtonElement>("#testbtn");
testbtn?.addEventListener("click", () => {
    send({
        msg: "aaa",
    });
});
