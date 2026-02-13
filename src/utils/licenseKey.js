function getNextKey() {
  const segment = () => Math.random().toString(36).slice(2, 10);
  return [segment(), segment(), segment(), segment()].join("-");
}

module.exports = { getNextKey };
