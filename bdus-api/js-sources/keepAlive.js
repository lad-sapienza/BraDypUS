const keepAlive = {
  ms: .6 * 60 * 1000, // 3 min
  interval: {},

  start(){
    this.interval = setInterval(this._keepAlive, this.ms)
  },
  stop(){
    clearInterval(this.interval);
  },
  _keepAlive: () => {
    core.getJSON('keepAlive_ctrl', 'run', false, false, r => {
      if (r.status !== 'success'){
        console.log(r.text);
        this.stop();
      }
    });
  }
}